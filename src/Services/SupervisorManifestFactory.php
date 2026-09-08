<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use Laravel\Reverb\ReverbServiceProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\ArtisanServiceProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\TimezoneProvider;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;

final readonly class SupervisorManifestFactory
{
    public function __construct(
        private SupervisorRuntimePaths $paths,
        private SupervisorChildEnvironment $environment,
        private HorizonSupervisorAdapter $horizon,
        private SupervisorCronExpression $cron,
        private TimezoneProvider $applicationTimezone,
        private SupervisorPhpExecutableResolver $phpExecutable,
        private ArtisanServiceProvider $services,
        private ?SupervisorRuntimeContext $context = null,
    ) {}

    public function forContext(SupervisorRuntimeContext $context): self
    {
        return new self(
            $this->paths instanceof SupervisorRuntimePaths ? new SupervisorRuntimePaths($context) : $this->paths,
            $this->environment,
            $this->horizon,
            $this->cron,
            $this->applicationTimezone,
            $this->phpExecutable,
            $this->services,
            $context,
        );
    }

    /** @return list<array{id:string,label:string,source:string,default_processes:int,max_processes:int,group:array<string,mixed>}> */
    public function definitions(): array
    {
        if ($this->context !== null) {
            $definitions = [];
            if ($this->context->queueEnabled) {
                $definitions[] = $this->queueDefinition($this->context->queue, 'context');
            }
            if ($this->context->schedulerEnabled) {
                $definitions[] = $this->schedulerDefinition((array) config('process-supervisor.scheduler', []));
            }
            if ($this->context->reverbEnabled && class_exists(ReverbServiceProvider::class)) {
                $definitions[] = $this->reverbDefinition((array) config('process-supervisor.reverb', []));
            }

            return $definitions;
        }
        $horizon = $this->horizon->projection();
        $profiles = $horizon['profiles'];
        if ($profiles === []) {
            $native = $this->queueDefinition((array) config('process-supervisor.queue', []), 'config');
            $native['capability_message'] = is_string($horizon['error'])
                ? 'Horizon configuration is incompatible with Windows one-shot supervision; using the native queue profile.'
                : null;
            $definitions = [$native];
        } else {
            $definitions = array_map(fn (array $profile): array => $this->queueDefinition($profile, 'horizon'), $profiles);
        }

        $definitions[] = $this->schedulerDefinition((array) config('process-supervisor.scheduler', []));

        if (class_exists(ReverbServiceProvider::class)) {
            $definitions[] = $this->reverbDefinition((array) config('process-supervisor.reverb', []));
        }

        foreach ($this->services->services() as $id => $service) {
            if (in_array($id, array_column($definitions, 'id'), true)) {
                throw new SupervisorRuntimeException('SUPERVISOR_SERVICE_INVALID', 'Duplicate configured service ID.', 503);
            }
            $definitions[] = $this->serviceDefinition($id, $service);
        }

        return $definitions;
    }

    /** @param array<string,mixed>|null $current @return array<string,mixed> */
    public function make(bool $enabled, int $revision, ?array $current = null): array
    {
        $currentGroups = [];
        foreach ((array) ($current['groups'] ?? []) as $group) {
            if (is_array($group) && is_string($group['id'] ?? null)) {
                $currentGroups[$group['id']] = $group;
            }
        }

        $runtime = $this->context === null
            ? [
                'project_root' => realpath(base_path()) ?: base_path(),
                'php_executable' => $this->phpExecutable->resolve(),
                'child_environment' => $this->environment->values(),
            ]
            : [
                'project_root' => $this->context->projectRoot,
                'php_executable' => $this->context->phpExecutable,
                'child_environment' => $this->context->childEnvironment,
            ];
        $runtimeChanged = is_array($current['runtime'] ?? null) && $current['runtime'] !== $runtime;

        $groups = [];
        foreach ($this->definitions() as $definition) {
            $group = $definition['group'];
            $previous = $currentGroups[$definition['id']] ?? null;
            if (is_array($previous)) {
                $generation = is_int($previous['generation'] ?? null) ? $previous['generation'] : 0;
                $desired = is_int($previous['desired_processes'] ?? null) ? $previous['desired_processes'] : 0;

                if ($runtimeChanged || ! $this->sameStaticGroup($group, $previous)) {
                    $generation++;
                }
                $group['generation'] = $generation;
                $group['desired_processes'] = $enabled
                    ? min(max(0, $desired), $definition['max_processes'])
                    : 0;
            }
            $groups[] = $group;
        }

        return [
            'schema_version' => 1,
            'installation_id' => $this->paths->installationId(),
            'revision' => max(0, $revision),
            'enabled' => $enabled,
            'generated_at' => now()->toISOString(),
            'runtime' => $runtime,
            'groups' => $groups,
        ];
    }

    /** @return array<string,mixed> */
    private function queueDefinition(array $config, string $source): array
    {
        $id = is_string($config['id'] ?? null) ? $config['id'] : 'queue-default';
        $label = is_string($config['label'] ?? null) ? $config['label'] : $id;
        $processes = max(1, min((int) config('process-supervisor.max_processes', 8), (int) ($config['processes'] ?? 1)));
        $queues = is_array($config['queues'] ?? null) ? array_values($config['queues']) : ['default'];
        $backoff = is_array($config['backoff'] ?? null) ? array_values($config['backoff']) : [0];
        $restart = is_array($config['restart_policy'] ?? null)
            ? $config['restart_policy']
            : (array) config('process-supervisor.queue.restart_policy', []);

        return [
            'id' => $id,
            'label' => $label,
            'source' => $source,
            'default_processes' => $processes,
            'max_processes' => (int) config('process-supervisor.max_processes', 8),
            'group' => [
                'id' => $id,
                'kind' => 'queue_once',
                'generation' => 0,
                'desired_processes' => 0,
                'stop_grace_seconds' => (float) ($config['stop_grace_seconds'] ?? 10),
                'restart_policy' => $this->restartPolicy($restart),
                'queue' => [
                    'connection' => (string) ($config['connection'] ?? config('queue.default')),
                    'queues' => $queues,
                    'backoff' => $backoff,
                    'tries' => max(1, (int) ($config['tries'] ?? 1)),
                    'sleep_seconds' => max(0, (float) ($config['sleep_seconds'] ?? 1)),
                    'watchdog_seconds' => max(0, (int) ($config['watchdog_seconds'] ?? 0)),
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function schedulerDefinition(array $config): array
    {
        $id = is_string($config['id'] ?? null) ? $config['id'] : 'scheduler';
        $cron = $this->cron->normalize(is_string($config['cron'] ?? null) ? $config['cron'] : '* * * * *');
        $timezone = $this->context?->timezone ?? $this->applicationTimezone->effective();

        return [
            'id' => $id,
            'label' => is_string($config['label'] ?? null) ? $config['label'] : 'Laravel scheduler',
            'source' => 'config',
            'capability_message' => null,
            'default_processes' => 1,
            'max_processes' => 1,
            'group' => [
                'id' => $id,
                'kind' => 'scheduler',
                'generation' => 0,
                'desired_processes' => 0,
                'stop_grace_seconds' => (float) ($config['stop_grace_seconds'] ?? 65),
                'restart_policy' => $this->restartPolicy((array) ($config['restart_policy'] ?? [])),
                'queue' => null,
                'scheduler' => [
                    'cron' => $cron,
                    'timezone' => $timezone,
                    'watchdog_seconds' => max(0, (int) ($config['watchdog_seconds'] ?? 90)),
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function reverbDefinition(array $config): array
    {
        $id = is_string($config['id'] ?? null) ? $config['id'] : 'reverb';

        return [
            'id' => $id,
            'label' => is_string($config['label'] ?? null) ? $config['label'] : 'Reverb',
            'source' => 'config',
            'default_processes' => 1,
            'max_processes' => 1,
            'group' => [
                'id' => $id,
                'kind' => 'reverb',
                'generation' => 0,
                'desired_processes' => 0,
                'stop_grace_seconds' => (float) ($config['stop_grace_seconds'] ?? 10),
                'restart_policy' => $this->restartPolicy((array) ($config['restart_policy'] ?? [])),
                'queue' => null,
            ],
        ];
    }

    private function serviceDefinition(string $id, array $service): array
    {
        $command = $service['command'] ?? null;
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $id) !== 1
            || ! is_string($command)
            || preg_match('/\A[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9:-]{0,127}\z/', $command) !== 1) {
            throw new SupervisorRuntimeException('SUPERVISOR_SERVICE_INVALID', 'A service requires a valid ID and one Artisan command name without arguments.', 503);
        }

        return [
            'id' => $id,
            'label' => $service['label'],
            'source' => 'service',
            'capability_message' => $service['description'] ?? null,
            'listen' => $service['listen'] ?? null,
            'default_processes' => 1,
            'max_processes' => 1,
            'group' => [
                'id' => $id,
                'kind' => 'artisan_service',
                'generation' => 0,
                'desired_processes' => 0,
                'stop_grace_seconds' => (float) ($service['stop_grace_seconds'] ?? 1),
                'restart_policy' => $this->restartPolicy([]),
                'queue' => null,
                'service' => ['command' => $command],
            ],
        ];
    }

    /** @return array{enabled:bool,base_delay_seconds:float,max_delay_seconds:float,crash_window_seconds:float,max_crashes:int} */
    private function restartPolicy(array $policy): array
    {
        return [
            'enabled' => (bool) ($policy['enabled'] ?? true),
            'base_delay_seconds' => max(0.05, (float) ($policy['base_delay_seconds'] ?? 0.25)),
            'max_delay_seconds' => max(0.05, (float) ($policy['max_delay_seconds'] ?? 10)),
            'crash_window_seconds' => max(1, (float) ($policy['crash_window_seconds'] ?? 60)),
            'max_crashes' => max(1, (int) ($policy['max_crashes'] ?? 5)),
        ];
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function sameStaticGroup(array $expected, array $actual): bool
    {
        foreach (['generation', 'desired_processes'] as $key) {
            $expected[$key] = 0;
            $actual[$key] = 0;
        }

        // JSON persistence normalizes integral floats (1.0 -> 1). A wire round trip
        // must not be mistaken for a configuration change or restart a sibling group.
        return json_encode($expected, JSON_THROW_ON_ERROR) === json_encode($actual, JSON_THROW_ON_ERROR);
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
