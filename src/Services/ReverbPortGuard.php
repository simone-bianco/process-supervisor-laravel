<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\ReverbPortProvider;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;

final readonly class ReverbPortGuard
{
    public function __construct(
        private SupervisorDiagnosticsRecorder $diagnostics,
        private ReverbPortProvider $reverbPort,
    ) {}

    public function assertAvailable(?int $port = null, ?string $host = null): void
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
            $this->rejectConflict($port, $normalizedHost, $connectError);
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

            return;
        }

        $this->rejectConflict($port, $normalizedHost, $errorNumber);
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
            "Reverb port {$port} is already in use. Choose a free port in Dev Tools before starting Reverb.",
            409,
        );
    }
}
