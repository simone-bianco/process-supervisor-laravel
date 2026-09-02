<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Contracts;

interface AvailabilityProvider
{
    public function enabled(): bool;
}
