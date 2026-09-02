<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;

final readonly class SupervisorControlLock
{
    public function __construct(private SupervisorRuntimePaths $paths) {}

    public function run(callable $callback): mixed
    {
        $this->paths->ensureRoot();
        $handle = @fopen($this->paths->controlLock(), 'c+b');
        if ($handle === false) {
            throw new SupervisorRuntimeException(
                'SUPERVISOR_LOCK_UNAVAILABLE',
                'The supervisor control lock is unavailable.',
                503,
            );
        }

        $timeoutMs = max(1, (int) config('process-supervisor.control_lock_timeout_ms', 5_000));
        $deadline = hrtime(true) + ($timeoutMs * 1_000_000);
        $acquired = false;
        try {
            do {
                $acquired = flock($handle, LOCK_EX | LOCK_NB);
                if ($acquired) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);

            if (! $acquired) {
                throw new SupervisorRuntimeException(
                    'SUPERVISOR_LOCK_TIMEOUT',
                    'Another Process Supervisor control operation is still in progress.',
                    409,
                );
            }

            return $callback();
        } finally {
            if ($acquired) {
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
        }
    }
}
