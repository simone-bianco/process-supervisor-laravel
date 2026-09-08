<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use JsonException;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\SupervisorControlClient;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;

final readonly class SupervisorPythonBridge implements SupervisorControlClient
{
    private const int MAX_OUTPUT_BYTES = 128_000;

    private const array COMMANDS = [
        'doctor',
        'apply-desired',
        'disable-gate',
        'ensure-running',
        'shutdown',
        'recover',
        'status',
    ];

    public function __construct(
        private SupervisorPythonExecutableResolver $python,
        private SupervisorRuntimePaths $paths,
    ) {}

    /** @param array<string, mixed>|null $input @return array<string, mixed> */
    public function run(string $command, ?array $input = null): array
    {
        if (! in_array($command, self::COMMANDS, true)) {
            throw new SupervisorRuntimeException('SUPERVISOR_COMMAND_REJECTED', 'The requested supervisor command is not allowed.', 422);
        }
        $module = config('process-supervisor.python_module');
        if (! is_string($module) || preg_match('/\A[a-zA-Z0-9_.]+\z/D', $module) !== 1) {
            throw new SupervisorRuntimeException('SUPERVISOR_NOT_CONFIGURED', 'The Python supervisor module is not configured.');
        }
        $executable = $this->python->resolve();
        if (! is_string($executable) || $executable === '' || ! is_file($executable)) {
            throw new SupervisorRuntimeException('SUPERVISOR_PYTHON_UNAVAILABLE', 'The application-owned Python runtime is unavailable.');
        }

        $arguments = [$executable, '-m', $module, $command, '--runtime-root', $this->paths->root()];
        if ($command !== 'doctor') {
            $arguments[] = '--installation-id';
            $arguments[] = $this->paths->installationId();
        }

        try {
            $payload = $input === null
                ? ''
                : json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new SupervisorRuntimeException('SUPERVISOR_PAYLOAD_INVALID', 'The process supervisor payload could not be encoded.', 422);
        }
        if (strlen($payload) > 1_000_000) {
            throw new SupervisorRuntimeException('SUPERVISOR_PAYLOAD_TOO_LARGE', 'The process supervisor payload exceeded the allowed size.', 422);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(
            $arguments,
            $descriptors,
            $pipes,
            base_path(),
            $this->controlEnvironment(),
            ['bypass_shell' => true],
        );
        if (! is_resource($process)) {
            throw new SupervisorRuntimeException('SUPERVISOR_START_FAILED', 'The process supervisor could not be started.');
        }

        $stdout = '';
        $stderrBytes = 0;
        $timedOut = false;
        $oversized = false;
        $observedExitCode = null;
        try {
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + $this->timeoutFor($command);

            while (true) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderrBytes += strlen((string) stream_get_contents($pipes[2]));
                if (strlen($stdout) > self::MAX_OUTPUT_BYTES || $stderrBytes > self::MAX_OUTPUT_BYTES) {
                    $oversized = true;
                    @proc_terminate($process);
                    break;
                }
                $status = proc_get_status($process);
                if (! is_array($status) || ($status['running'] ?? false) !== true) {
                    if (is_array($status) && is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                        $observedExitCode = $status['exitcode'];
                    }
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    @proc_terminate($process);
                    break;
                }
                usleep(10_000);
            }

            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderrBytes += strlen((string) stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $exitCode = proc_close($process);
            if ($exitCode < 0 && $observedExitCode !== null) {
                $exitCode = $observedExitCode;
            }
        }

        if ($timedOut) {
            throw new SupervisorRuntimeException('SUPERVISOR_TIMEOUT', 'The process supervisor did not respond before its deadline.', 504);
        }
        if ($oversized || strlen($stdout) > self::MAX_OUTPUT_BYTES || $stderrBytes > self::MAX_OUTPUT_BYTES) {
            throw new SupervisorRuntimeException('SUPERVISOR_RESPONSE_TOO_LARGE', 'The process supervisor response exceeded the allowed size.', 502);
        }

        try {
            $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SupervisorRuntimeException('SUPERVISOR_RESPONSE_INVALID', 'The process supervisor returned an invalid response.', 502);
        }
        if (! is_array($decoded) || ! is_bool($decoded['ok'] ?? null)) {
            throw new SupervisorRuntimeException('SUPERVISOR_RESPONSE_INVALID', 'The process supervisor response contract is invalid.', 502);
        }
        if ($decoded['ok'] !== true || $exitCode !== 0) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            if (($error['code'] ?? null) === 'RESIDENT_UPGRADE_REQUIRED') {
                throw new SupervisorRuntimeException(
                    'RESIDENT_UPGRADE_REQUIRED',
                    'The resident engine must be updated before adding this service. Disable Process Supervisor in Dev Tools, wait for shutdown, then enable it again and start the required groups.',
                    409,
                );
            }
            throw new SupervisorRuntimeException(
                is_string($error['code'] ?? null) ? $error['code'] : 'SUPERVISOR_CONTROL_FAILED',
                'The process supervisor could not complete the requested operation.',
                503,
            );
        }

        unset($decoded['ok']);

        return $decoded;
    }

    /** @return array<string, string> */
    private function controlEnvironment(): array
    {
        $environment = [
            'PYTHONUTF8' => '1',
            'PYTHONIOENCODING' => 'utf-8',
        ];
        foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH', 'PATHEXT', 'USERPROFILE', 'HOME'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '' && ! str_contains($value, "\0")) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private function timeoutFor(string $command): float
    {
        $key = match ($command) {
            'status', 'doctor' => 'process-supervisor.status_timeout_seconds',
            'shutdown' => 'process-supervisor.shutdown_timeout_seconds',
            default => 'process-supervisor.control_timeout_seconds',
        };

        return max(1.0, min(30.0, (float) config($key, 8)));
    }
}
