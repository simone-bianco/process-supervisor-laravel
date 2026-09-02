<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Events\SupervisorReverbProbe;

final class SupervisorReverbClientProjection
{
    /** @return array{app_key:string,host:string,port:int,scheme:string,channel:string,event:string}|null */
    public function get(): ?array
    {
        $appKey = config('reverb.apps.apps.0.key');
        $options = config('reverb.apps.apps.0.options');
        if (! is_string($appKey) || $appKey === '' || ! is_array($options)) {
            return null;
        }

        $host = $options['host'] ?? null;
        $port = $options['port'] ?? null;
        $scheme = $options['scheme'] ?? null;
        if (! is_string($host) || $host === '' || ! is_numeric($port) || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            'app_key' => $appKey,
            'host' => $host,
            'port' => (int) $port,
            'scheme' => $scheme === 'https' ? 'wss' : 'ws',
            'channel' => SupervisorReverbProbe::CHANNEL,
            'event' => SupervisorReverbProbe::EVENT,
        ];
    }
}
