<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Contracts;

interface SupervisorControlClient
{
    /** @param array<string,mixed>|null $input @return array<string,mixed> */
    public function run(string $command, ?array $input = null): array;
}
