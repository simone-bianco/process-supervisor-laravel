<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Contracts;

interface ReverbPortProvider
{
    public function effective(): int;
}
