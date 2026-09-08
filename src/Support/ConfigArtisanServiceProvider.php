<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Support;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\ArtisanServiceProvider;

final class ConfigArtisanServiceProvider implements ArtisanServiceProvider
{
    public function services(): array
    {
        return (array) config('process-supervisor.services', []);
    }
}
