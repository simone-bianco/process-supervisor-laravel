<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\ReverbPortProvider;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final readonly class ReverbPortGuard
{
    public function __construct(
        private SupervisorDiagnosticsRecorder $diagnostics,
        private ReverbPortProvider $reverbPort,
    ) {}

    public function assertAvailable(?int $port = null, ?string $host = null): void
    {
        $this->checkEndpoint($port, $host, false);
    }

    public function prepareForStart(): void
    {
        $this->checkEndpoint(null, null, app()->environment('local') && PHP_OS_FAMILY === 'Windows');
    }

    private function checkEndpoint(?int $port, ?string $host, bool $releaseConflict): void
    {
        $host ??= config('reverb.servers.reverb.host', '127.0.0.1');
        $port ??= $this->reverbPort->effective();
        if (! is_string($host) || trim($host) === '' || ! is_int($port)) {
            throw new SupervisorRuntimeException(
                'SUPERVISOR_REVERB_ENDPOINT_INVALID',
                'The configured Reverb bind endpoint is invalid.',
                503,
            );
        }
        if ($port < 1024 || $port > 65_535) {
            throw new SupervisorRuntimeException(
                'SUPERVISOR_REVERB_ENDPOINT_INVALID',
                'The configured Reverb port must be between 1024 and 65535.',
                503,
            );
        }

        $normalizedHost = trim($host, '[]');
        $uri = str_contains($normalizedHost, ':')
            ? "tcp://[{$normalizedHost}]:{$port}"
            : "tcp://{$normalizedHost}:{$port}";
        $socketError = $this->probe($uri);
        if ($socketError === null) {
            return;
        }

        if ($releaseConflict && filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false) {
            $this->releaseWindowsListener($normalizedHost, $port);
            $socketError = $this->probe($uri);
            if ($socketError === null) {
                return;
            }
        }

        $this->rejectConflict($port, $normalizedHost, $socketError);
    }

    private function probe(string $uri): ?int
    {
        $connectError = 0;
        $connectMessage = '';
        $existing = @stream_socket_client(
            $uri,
            $connectError,
            $connectMessage,
            0.25,
            STREAM_CLIENT_CONNECT,
        );
        if (is_resource($existing)) {
            fclose($existing);

            return $connectError;
        }

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_server(
            $uri,
            $errorNumber,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if (is_resource($socket)) {
            fclose($socket);

            return null;
        }

        return $errorNumber;
    }

    private function releaseWindowsListener(string $host, int $port): void
    {
        $script = <<<'POWERSHELL'
            $address = [System.Net.IPAddress]::Parse($env:SUPERVISOR_REVERB_BIND_IP)
            $wildcard = [System.Net.IPAddress]::Any
            if ($address.AddressFamily -eq 'InterNetworkV6') {
                $wildcard = [System.Net.IPAddress]::IPv6Any
            }
            function Get-Owners {
                Get-NetTCPConnection -State Listen -LocalPort ([int]$env:SUPERVISOR_REVERB_BIND_PORT) -ErrorAction SilentlyContinue |
                    Where-Object {
                        $listener = [System.Net.IPAddress]::Parse($_.LocalAddress)
                        $listener.AddressFamily -eq $address.AddressFamily -and
                            ($listener.Equals($address) -or $listener.Equals($wildcard) -or $address.Equals($wildcard))
                    } |
                    Select-Object -ExpandProperty OwningProcess -Unique
            }
            # Allow a launcher with a short restart delay to reveal any replacement listener.
            for ($attempt = 0; $attempt -lt 10; $attempt++) {
                $stopped = $false
                foreach ($owner in Get-Owners) {
                    if ($owner -le 4 -or $owner -eq $PID -or $owner -eq [int]$env:SUPERVISOR_REVERB_CALLER_PID) { continue }
                    try {
                        $process = Get-Process -Id $owner -ErrorAction Stop
                        try {
                            # Hold the process handle while confirming that it still owns the listener.
                            $null = $process.Handle
                            if (!$process.HasExited -and (Get-Owners) -contains $owner) {
                                $process.Kill()
                                $stopped = $process.WaitForExit(1200) -or $stopped
                            }
                        } finally {
                            $process.Dispose()
                        }
                    } catch {
                        # The endpoint probe reports any conflict that remains.
                    }
                }
                if (!$stopped) { break }
                Start-Sleep -Milliseconds 1300
            }
            POWERSHELL;

        $process = new Process(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script],
            env: [
                'SUPERVISOR_REVERB_BIND_IP' => $host,
                'SUPERVISOR_REVERB_BIND_PORT' => (string) $port,
                'SUPERVISOR_REVERB_CALLER_PID' => (string) getmypid(),
            ],
            timeout: 30,
        );
        $process->disableOutput();

        try {
            $process->run();
        } catch (RuntimeException) {
            // The endpoint probe reports any conflict that remains.
        }
    }

    private function rejectConflict(int $port, string $host, int $socketError): never
    {
        $this->diagnostics->recordControlFailure(
            'process_supervisor.reverb_port_conflict',
            'Reverb could not start because its configured local port is already in use.',
            [
                'reason_code' => 'reverb_port_in_use',
                'reverb_port' => $port,
                'bind_host' => $host,
                'socket_error' => $socketError,
            ],
        );

        throw new SupervisorRuntimeException(
            'SUPERVISOR_REVERB_PORT_IN_USE',
            "Reverb could not bind to {$host} on port {$port}. Check the listener and permissions, or choose another port in Dev Tools.",
            409,
        );
    }
}
