<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\ReverbPortProvider;

final readonly class SupervisorChildEnvironment
{
    public function __construct(private ReverbPortProvider $reverbPort) {}

    /** @return array<string, string> */
    public function values(): array
    {
        $candidates = [
            'APP_ENV' => app()->environment(),
            'APP_DEBUG' => (bool) config('app.debug') ? '1' : '0',
            'APP_URL' => config('app.url'),
            'BROADCAST_CONNECTION' => config('broadcasting.default'),
            'CACHE_PREFIX' => config('cache.prefix'),
            'CACHE_STORE' => config('cache.default'),
            'DB_CONNECTION' => config('database.default'),
            'LOG_CHANNEL' => config('logging.default'),
            'LOG_LEVEL' => config('logging.channels.stack.level'),
            'QUEUE_CONNECTION' => config('queue.default'),
            'REDIS_CLIENT' => config('database.redis.client'),
            'REDIS_DB' => config('database.redis.default.database'),
            'REDIS_HOST' => config('database.redis.default.host'),
            'REDIS_PORT' => config('database.redis.default.port'),
            'REVERB_HOST' => config('reverb.apps.apps.0.options.host'),
            'REVERB_PORT' => $this->reverbPort->effective(),
            'REVERB_SCHEME' => config('reverb.apps.apps.0.options.scheme'),
            'REVERB_SERVER_HOST' => config('reverb.servers.reverb.host'),
            'REVERB_SERVER_PORT' => $this->reverbPort->effective(),
        ];

        $values = [];
        foreach ($candidates as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (! is_string($value) || $value === '' || strlen($value) > 2048 || str_contains($value, "\0")) {
                continue;
            }
            $values[$key] = $value;
        }

        ksort($values, SORT_STRING);

        return $values;
    }
}
