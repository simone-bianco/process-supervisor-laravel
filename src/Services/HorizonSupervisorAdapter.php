<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use Illuminate\Support\Str;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;

final readonly class HorizonSupervisorAdapter
{
    /** @return array{profiles:list<array<string,mixed>>,error:string|null} */
    public function projection(): array
    {
        try {
            return ['profiles' => $this->profiles(), 'error' => null];
        } catch (SupervisorRuntimeException $exception) {
            return ['profiles' => [], 'error' => $exception->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    public function profiles(): array
    {
        $defaults = config('horizon.defaults');
        $environments = config('horizon.environments');
        if (! is_array($defaults) || ! is_array($environments)) {
            return [];
        }

        $plan = null;
        foreach ($environments as $pattern => $candidate) {
            if (is_string($pattern) && Str::is($pattern, app()->environment()) && is_array($candidate) && ! array_is_list($candidate)) {
                $plan = $candidate;
                break;
            }
        }
        if ($plan === null) {
            return [];
        }
        if (array_is_list($defaults)) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', 'Horizon defaults must be keyed by supervisor name.', 422);
        }

        $names = array_values(array_unique([...array_keys($defaults), ...array_keys($plan)]));
        sort($names, SORT_STRING);
        $profiles = [];
        foreach ($names as $name) {
            $base = $defaults[$name] ?? [];
            $override = $plan[$name] ?? [];
            if (
                ! is_string($name)
                || ! is_array($base)
                || ($base !== [] && array_is_list($base))
                || ! is_array($override)
                || ($override !== [] && array_is_list($override))
            ) {
                throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', 'The Horizon supervisor configuration is invalid.', 422);
            }
            $profiles[] = $this->normalize($name, array_replace($base, $override));
        }

        return $profiles;
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private function normalize(string $name, array $profile): array
    {
        $allowed = [
            'connection', 'queue', 'backoff', 'tries', 'sleep', 'timeout', 'processes', 'maxProcesses', 'minProcesses',
            'balance', 'autoScalingStrategy', 'balanceMaxShift', 'balanceCooldown', 'maxTime', 'maxJobs', 'rest', 'force', 'memory', 'nice',
        ];
        $unknown = array_diff(array_keys($profile), $allowed);
        if ($unknown !== []) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', 'The Horizon supervisor configuration contains unsupported fields.', 422);
        }
        foreach (['balance' => [null, false], 'autoScalingStrategy' => [null], 'balanceMaxShift' => [null, 0], 'balanceCooldown' => [null, 0], 'maxTime' => [null, 0], 'rest' => [null, 0], 'force' => [null, false], 'nice' => [null, 0]] as $field => $neutral) {
            if (array_key_exists($field, $profile) && ! in_array($profile[$field], $neutral, true)) {
                throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', "Horizon supervisor [{$name}] field [{$field}] requires daemon semantics unavailable to the Windows one-shot adapter.", 422);
            }
        }
        if (array_key_exists('memory', $profile) && $profile['memory'] !== null) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', "Horizon supervisor [{$name}] field [memory] has no one-shot equivalent.", 422);
        }
        if (array_key_exists('maxJobs', $profile) && ! in_array($profile['maxJobs'], [null, 0, 1], true)) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', "Horizon supervisor [{$name}] field [maxJobs] must be absent, 0, or 1 for one-shot supervision.", 422);
        }
        if (array_key_exists('processes', $profile) && array_key_exists('maxProcesses', $profile) && $profile['processes'] !== $profile['maxProcesses']) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', "Horizon supervisor [{$name}] has conflicting static process counts.", 422);
        }

        $max = $profile['maxProcesses'] ?? $profile['processes'] ?? 1;
        $min = $profile['minProcesses'] ?? $max;
        if (! is_int($max) || ! is_int($min) || $max < 1 || $min !== $max || $max > (int) config('process-supervisor.max_processes', 8)) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', 'The Horizon supervisor process bounds are not static or are outside the allowed range.', 422);
        }
        $connection = $profile['connection'] ?? config('queue.default');
        if (! is_string($connection) || $connection === '') {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', 'The Horizon supervisor connection is invalid.', 422);
        }
        $queue = $profile['queue'] ?? ['default'];
        $queues = is_string($queue) ? array_map('trim', explode(',', $queue)) : $queue;
        if (! is_array($queues) || $queues === [] || array_any($queues, static fn (mixed $item): bool => ! is_string($item) || trim($item) === '')) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', 'The Horizon supervisor queue list is invalid.', 422);
        }
        $backoff = $profile['backoff'] ?? [0];
        if (is_int($backoff)) {
            $backoff = [$backoff];
        }
        if (! is_array($backoff) || $backoff === [] || array_any($backoff, static fn (mixed $item): bool => ! is_int($item) || $item < 0)) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', 'The Horizon supervisor backoff value is invalid.', 422);
        }

        return [
            'id' => 'horizon-'.Str::slug($name),
            'label' => $name,
            'connection' => $connection,
            'queues' => array_values(array_map('trim', $queues)),
            'processes' => $max,
            'tries' => $this->boundedInt($profile['tries'] ?? 1, 1, 100, "{$name}.tries"),
            'backoff' => array_values($backoff),
            'sleep_seconds' => $this->boundedNumber($profile['sleep'] ?? 1, 0, 3600, "{$name}.sleep"),
            'watchdog_seconds' => $this->boundedInt($profile['timeout'] ?? 0, 0, 86400, "{$name}.timeout"),
            'stop_grace_seconds' => 10,
            'source' => 'horizon',
        ];
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', "Horizon [{$label}] is outside the supported one-shot range.", 422);
        }

        return $value;
    }

    private function boundedNumber(mixed $value, float $minimum, float $maximum, string $label): float
    {
        if ((! is_int($value) && ! is_float($value)) || $value < $minimum || $value > $maximum) {
            throw new SupervisorRuntimeException('HORIZON_CONFIG_UNSUPPORTED', "Horizon [{$label}] is outside the supported one-shot range.", 422);
        }

        return (float) $value;
    }
}
