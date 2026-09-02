<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Support;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\ReverbPortProvider;

final readonly class ConfigReverbPortProvider implements ReverbPortProvider
{
    public function effective(): int
    {
        $value = config('process-supervisor.reverb.port', config('reverb.servers.reverb.port', 8080));
        $port = is_numeric($value) ? (int) $value : 8080;

        return $port >= 1024 && $port <= 65_535 ? $port : 8080;
    }
}
