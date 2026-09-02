<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use JsonException;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;
use Throwable;

final readonly class SupervisorReconciliationMarker
{
    public function __construct(private SupervisorRuntimePaths $paths) {}

    public function mark(string $reasonCode): void
    {
        $this->paths->ensureRoot();
        $target = $this->paths->reconciliation();
        $temporary = $target.'.'.bin2hex(random_bytes(6)).'.tmp';
        $payload = json_encode([
            'schema_version' => 1,
            'reason_code' => $reasonCode,
            'recorded_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($temporary, $payload, LOCK_EX) === false || ! @rename($temporary, $target)) {
            @unlink($temporary);
            report(new SupervisorRuntimeException(
                'SUPERVISOR_RECONCILIATION_MARKER_FAILED',
                'The supervisor recovery marker could not be persisted.',
            ));
        }
    }

    public function clear(): void
    {
        $path = $this->paths->reconciliation();
        if (is_file($path) && ! @unlink($path)) {
            report(new SupervisorRuntimeException(
                'SUPERVISOR_RECONCILIATION_MARKER_FAILED',
                'The supervisor recovery marker could not be cleared.',
            ));
        }
    }

    public function reason(): ?string
    {
        $path = $this->paths->reconciliation();
        if (! is_file($path)) {
            return null;
        }

        try {
            $payload = $this->read($path);
        } catch (Throwable $exception) {
            report($exception);

            return 'disable_reconciliation_marker_invalid';
        }

        $reasonCode = is_string($payload['reason_code'] ?? null)
            ? $payload['reason_code']
            : null;

        return $reasonCode ?: 'disable_reconciliation_marker_invalid';
    }

    /** @return array<string,mixed> */
    private function read(string $path): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                usleep(10_000);

                continue;
            }

            try {
                $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new SupervisorRuntimeException(
                    'SUPERVISOR_STATE_INVALID',
                    'The process supervisor recovery marker is invalid.',
                    503,
                );
            }

            if (! is_array($decoded)) {
                throw new SupervisorRuntimeException(
                    'SUPERVISOR_STATE_INVALID',
                    'The process supervisor recovery marker is invalid.',
                    503,
                );
            }

            return $decoded;
        }

        throw new SupervisorRuntimeException(
            'SUPERVISOR_STATE_UNAVAILABLE',
            'The process supervisor recovery marker could not be read.',
            503,
        );
    }
}
