<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Contracts;

interface ArtisanServiceProvider
{
    /**
     * Server-owned singleton services. Never populate from an HTTP payload.
     *
     * @return array<string,array{label:string,command:string,description?:string,listen?:array{host:string,port:int},stop_grace_seconds?:float}>
     */
    public function services(): array;
}
