<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Contracts;

interface TimezoneProvider
{
    public function effective(): string;
}
