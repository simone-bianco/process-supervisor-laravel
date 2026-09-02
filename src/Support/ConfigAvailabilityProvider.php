<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Support;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\AvailabilityProvider;

final readonly class ConfigAvailabilityProvider implements AvailabilityProvider
{
    public function enabled(): bool
    {
        return (bool) config('process-supervisor.enabled', false);
    }
}
