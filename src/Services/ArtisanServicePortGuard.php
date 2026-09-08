<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;

final class ArtisanServicePortGuard
{
    public function assertAvailable(array $definition): void
    {
        $listen = $definition['listen'] ?? null;
        if ($listen === null) {
            return;
        }
        $host = $listen['host'] ?? null;
        $port = filter_var($listen['port'] ?? null, FILTER_VALIDATE_INT);
        if (! is_string($host) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false
            || ! is_int($port) || $port < 1 || $port > 65535) {
            throw new SupervisorRuntimeException('SUPERVISOR_SERVICE_ENDPOINT_INVALID', 'The configured service bind endpoint is invalid.', 503);
        }
        $host = trim($host, '[]');
        $uri = str_contains($host, ':') ? "tcp://[{$host}]:{$port}" : "tcp://{$host}:{$port}";
        $socket = @stream_socket_client($uri, $code, $message, 0.15);
        if (is_resource($socket)) {
            fclose($socket);
            $this->conflict($definition['label'], $port);
        }
        $socket = @stream_socket_server($uri, $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (! is_resource($socket)) {
            $this->conflict($definition['label'], $port);
        }
        fclose($socket);
    }

    private function conflict(string $label, int $port): never
    {
        throw new SupervisorRuntimeException(
            'SUPERVISOR_SERVICE_PORT_IN_USE',
            "{$label} cannot start: port {$port} is already occupied. Stop the existing listener from the launcher that owns it, then press Start here. No external process was stopped or adopted.",
            409,
        );
    }
}
