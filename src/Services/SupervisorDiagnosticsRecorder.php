<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\DiagnosticsSink;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\ReverbPortProvider;
use Throwable;

final readonly class SupervisorDiagnosticsRecorder
{
    public function __construct(
        private SupervisorRuntimePaths $paths,
        private ReverbPortProvider $reverbPort,
        private DiagnosticsSink $sink,
    ) {}

    /** @param array<string,mixed> $status */
    public function record(array $status): void
    {
        $lock = null;
        try {
            $this->paths->ensureRoot();
            $lock = @fopen($this->paths->diagnosticsLock(), 'c+b');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire Process Supervisor diagnostics lock.');
            }

            $previous = $this->readState();
            $next = ['schema_version' => 1, 'runtime' => null, 'groups' => []];

            $summary = is_string($status['summary'] ?? null) ? $status['summary'] : 'unsupported';
            $reason = is_string($status['reason_code'] ?? null) ? $status['reason_code'] : null;
            if (in_array($summary, ['recovery_required', 'degraded', 'unsupported'], true)) {
                $runtimeFingerprint = $this->fingerprint([$summary, $reason]);
                $next['runtime'] = $runtimeFingerprint;
                if (($previous['runtime'] ?? null) !== $runtimeFingerprint) {
                    $this->store(
                        event: 'process_supervisor.runtime_failure',
                        component: 'process-supervisor.runtime',
                        level: $summary === 'degraded' ? 'warning' : 'error',
                        message: 'Process Supervisor entered a failure state.',
                        context: [
                            'runtime_summary' => $summary,
                            'reason_code' => $reason,
                            'heartbeat_at' => is_string($status['heartbeat_at'] ?? null) ? $status['heartbeat_at'] : null,
                            'reverb_port' => $this->desiredReverbPort(),
                            'configured_reverb_port' => $this->reverbPort->effective(),
                        ],
                    );
                }
            }

            foreach ((array) ($status['groups'] ?? []) as $group) {
                if (! is_array($group) || ! is_string($group['id'] ?? null)) {
                    continue;
                }
                $groupId = $group['id'];
                foreach ((array) ($group['instances'] ?? []) as $instance) {
                    if (! is_array($instance)) {
                        continue;
                    }
                    $state = is_string($instance['state'] ?? null) ? $instance['state'] : 'uncertain';
                    $errorCode = is_string($instance['last_error_code'] ?? null) ? $instance['last_error_code'] : null;
                    if (! in_array($state, ['backoff', 'fatal', 'uncertain'], true) && $errorCode === null) {
                        continue;
                    }

                    $slot = max(0, (int) ($instance['slot'] ?? 0));
                    $key = $groupId.':'.$slot;
                    $fingerprint = $this->fingerprint([
                        $groupId,
                        $slot,
                        $state,
                        $errorCode,
                        $instance['last_exit_code'] ?? null,
                        $instance['restart_count'] ?? 0,
                        $group['desired_processes'] ?? 0,
                    ]);
                    $next['groups'][$key] = $fingerprint;
                    if (($previous['groups'][$key] ?? null) === $fingerprint) {
                        continue;
                    }

                    $this->store(
                        event: 'process_supervisor.group_failure',
                        component: 'process-supervisor.group',
                        level: $state === 'fatal' || $state === 'uncertain' ? 'error' : 'warning',
                        message: 'A Process Supervisor group instance entered a failure state.',
                        context: [
                            'group_id' => $groupId,
                            'group_kind' => is_string($group['kind'] ?? null) ? $group['kind'] : null,
                            'slot' => $slot,
                            'instance_state' => $state,
                            'pid' => is_int($instance['pid'] ?? null) ? $instance['pid'] : null,
                            'error_code' => $errorCode,
                            'exit_code' => is_int($instance['last_exit_code'] ?? null) ? $instance['last_exit_code'] : null,
                            'restart_count' => max(0, (int) ($instance['restart_count'] ?? 0)),
                            'desired_processes' => max(0, (int) ($group['desired_processes'] ?? 0)),
                            'reverb_port' => $groupId === 'reverb' ? $this->desiredReverbPort() : null,
                            'configured_reverb_port' => $groupId === 'reverb' ? $this->reverbPort->effective() : null,
                        ],
                    );
                }
            }

            if ($previous !== $next) {
                $this->writeState($next);
            }
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /** @param array<string,mixed> $context */
    public function recordControlFailure(string $event, string $message, array $context = []): void
    {
        try {
            $this->store(
                event: $event,
                component: 'process-supervisor.control',
                level: 'error',
                message: $message,
                context: $context,
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }
    }

    private function desiredReverbPort(): int
    {
        $contents = @file_get_contents($this->paths->desired());
        if ($contents !== false) {
            $decoded = json_decode($contents, true);
            $value = is_array($decoded) ? ($decoded['runtime']['child_environment']['REVERB_SERVER_PORT'] ?? null) : null;
            if (is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            }
            if (is_int($value) && $value >= 1024 && $value <= 65_535) {
                return $value;
            }
        }

        return $this->reverbPort->effective();
    }

    /** @param array<string,mixed> $context */
    private function store(string $event, string $component, string $level, string $message, array $context): void
    {
        $this->sink->record([
            'level' => $level,
            'component' => $component,
            'event' => $event,
            'message' => $message,
            'context' => $context,
        ]);
    }

    /** @return array{schema_version:int,runtime:string|null,groups:array<string,string>} */
    private function readState(): array
    {
        $path = $this->paths->diagnosticsState();
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return ['schema_version' => 1, 'runtime' => null, 'groups' => []];
        }
        $decoded = json_decode($contents, true);
        if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1) {
            return ['schema_version' => 1, 'runtime' => null, 'groups' => []];
        }

        return [
            'schema_version' => 1,
            'runtime' => is_string($decoded['runtime'] ?? null) ? $decoded['runtime'] : null,
            'groups' => is_array($decoded['groups'] ?? null) ? $decoded['groups'] : [],
        ];
    }

    /** @param array{schema_version:int,runtime:string|null,groups:array<string,string>} $state */
    private function writeState(array $state): void
    {
        $root = $this->paths->ensureRoot();
        $target = $this->paths->diagnosticsState();
        $temporary = $root.DIRECTORY_SEPARATOR.'.diagnostics-'.bin2hex(random_bytes(6)).'.tmp';
        $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false || ! @rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to persist Process Supervisor diagnostic dedupe state.');
        }
    }

    /** @param list<mixed> $parts */
    private function fingerprint(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function reportFailure(Throwable $exception): void
    {
        try {
            error_log(json_encode([
                'level' => 'warning',
                'component' => 'process-supervisor.diagnostics',
                'event' => 'process_supervisor.diagnostics_write_failed',
                'message' => 'Process Supervisor diagnostics could not be persisted.',
                'context' => ['exception' => $exception::class],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // Diagnostics must stay fail-open.
        }
    }
}
