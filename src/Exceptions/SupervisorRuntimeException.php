<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Exceptions;

use RuntimeException;

final class SupervisorRuntimeException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 503,
    ) {
        parent::__construct($message);
    }
}
