<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Support;

use DateTimeZone;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\TimezoneProvider;

final readonly class ConfigTimezoneProvider implements TimezoneProvider
{
    public function effective(): string
    {
        $value = config('app.timezone', 'UTC');

        return is_string($value) && $this->valid($value) ? $value : 'UTC';
    }

    private function valid(string $timezone): bool
    {
        return $timezone === 'UTC'
            || in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }
}
