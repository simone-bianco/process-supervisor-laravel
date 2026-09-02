<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Contracts;

interface DiagnosticsSink
{
    /** @param array<string,mixed> $entry */
    public function record(array $entry): void;
}
