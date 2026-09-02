<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use Illuminate\Support\Carbon;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\AvailabilityProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\TimezoneProvider;
use Throwable;

final readonly class SupervisorStatusPresenter
{
    public function __construct(
        private SupervisorManifestFactory $manifests,
        private AvailabilityProvider $availability,
        private SupervisorCronExpression $cron,
        private TimezoneProvider $applicationTimezone,
        private SupervisorDiagnosticsRecorder $diagnostics,
    ) {}

    /** @param array<string,mixed> $status @param array<string,mixed>|null $desired @return array<string,mixed> */
    public function present(array $status, ?array $desired): array
    {
        $runtimeSummary = $this->summary($status);
        $definitions = collect($this->manifests->definitions())->keyBy('id');
        $statusGroups = collect((array) ($status['groups'] ?? []))
            ->filter(static fn (mixed $group): bool => is_array($group) && is_string($group['id'] ?? null))
            ->keyBy('id');
        $desiredGroups = collect((array) ($desired['groups'] ?? []))
            ->filter(static fn (mixed $group): bool => is_array($group) && is_string($group['id'] ?? null))
            ->keyBy('id');
        $heartbeatState = $this->heartbeatState($status, $desired);
        if ($heartbeatState === 'stale') {
            $runtimeSummary = 'recovery_required';
        } elseif (
            $heartbeatState === 'starting'
            && ! in_array($runtimeSummary, ['recovery_required', 'unsupported', 'degraded'], true)
        ) {
            $runtimeSummary = 'starting';
        }
        $recoveryConstrained = $runtimeSummary === 'recovery_required';
        $groups = [];

        foreach ($definitions as $id => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $desiredGroup = $desiredGroups->get($id);
            $statusGroup = $statusGroups->get($id);
            $group = array_replace(
                $definition['group'],
                is_array($statusGroup) ? $statusGroup : [],
                is_array($desiredGroup) ? $desiredGroup : [],
            );
            $instances = [];
            foreach ((array) ($group['instances'] ?? []) as $instance) {
                if (! is_array($instance)) {
                    continue;
                }
                $instanceState = is_string($instance['state'] ?? null) ? $instance['state'] : 'uncertain';
                $instanceError = is_string($instance['last_error_code'] ?? null) ? $instance['last_error_code'] : null;
                if ($recoveryConstrained && in_array($instanceState, ['starting', 'running', 'stopping'], true)) {
                    $instanceState = 'uncertain';
                    $instanceError ??= 'runtime_recovery_required';
                }
                $instances[] = [
                    'id' => $id.':'.(int) ($instance['slot'] ?? 0),
                    'slot' => max(0, (int) ($instance['slot'] ?? 0)),
                    'state' => $instanceState,
                    'pid' => is_int($instance['pid'] ?? null) ? $instance['pid'] : null,
                    'uptime_seconds' => null,
                    'restart_count' => max(0, (int) ($instance['restart_count'] ?? 0)),
                    'last_exit_code' => is_int($instance['last_exit_code'] ?? null) ? $instance['last_exit_code'] : null,
                    'last_error_code' => $instanceError,
                ];
            }

            $desiredProcesses = max(0, (int) ($group['desired_processes'] ?? 0));
            $kind = $group['kind'] ?? $definition['group']['kind'];
            $healthyStates = $kind === 'queue_once' ? ['running', 'idle'] : ['running'];
            $isRunning = $desiredProcesses > 0 && collect($instances)->contains(
                static fn (array $instance): bool => in_array($instance['state'] ?? null, $healthyStates, true),
            );
            $groupCron = ($group['kind'] ?? null) === 'scheduler' && is_string($group['scheduler']['cron'] ?? null)
                ? $group['scheduler']['cron']
                : null;
            $groupTimezone = ($group['kind'] ?? null) === 'scheduler' && is_string($group['scheduler']['timezone'] ?? null)
                ? $group['scheduler']['timezone']
                : null;

            $groups[] = [
                'id' => $id,
                'label' => $definition['label'],
                'kind' => $kind,
                'desired_processes' => $desiredProcesses,
                'max_processes' => $definition['max_processes'],
                'instances' => $instances,
                'is_running' => $isRunning,
                'can_start' => ! $recoveryConstrained && $desiredProcesses === 0,
                'can_stop' => $desiredProcesses > 0,
                'can_restart' => ! $recoveryConstrained && $desiredProcesses > 0,
                'can_probe' => ! $recoveryConstrained
                    && $isRunning
                    && in_array(($definition['group']['kind'] ?? null), ['queue_once', 'reverb'], true),
                'cron' => $groupCron,
                'timezone' => $groupTimezone,
                'next_run_at' => $groupCron !== null ? $this->cron->nextRunAt($groupCron, $groupTimezone) : null,
                'capability_message' => is_string($definition['capability_message'] ?? null)
                    ? $definition['capability_message']
                    : ($definition['source'] === 'horizon' ? 'Imported from the supported static Horizon configuration subset.' : null),
            ];
        }

        $enabled = $this->availability->enabled();
        $summary = $runtimeSummary;
        $reasonCode = is_string($status['reason_code'] ?? null) ? $status['reason_code'] : null;
        if ($heartbeatState === 'stale') {
            $reasonCode = 'resident_heartbeat_stale';
        } elseif ($heartbeatState === 'starting') {
            $reasonCode = null;
        }
        if (! $enabled && ! in_array($summary, ['disabled', 'stopped', 'recovery_required'], true)) {
            $summary = 'recovery_required';
            $reasonCode ??= 'disabled_gate_runtime_not_stopped';
        }

        $projection = [
            'enabled' => $enabled,
            'can_recover' => $summary === 'recovery_required',
            'summary' => $summary,
            'platform' => PHP_OS_FAMILY.' / Process Supervisor',
            'timezone' => $this->applicationTimezone->effective(),
            'heartbeat_at' => is_string($status['heartbeat_at'] ?? null) ? $status['heartbeat_at'] : null,
            'reason_code' => $reasonCode,
            'groups' => $groups,
        ];
        $this->diagnostics->record($projection);

        return $projection;
    }

    /** @return array<string,mixed> */
    public function fallback(bool $enabled, string $summary, ?string $reasonCode): array
    {
        $projection = [
            'enabled' => $enabled,
            'can_recover' => $summary === 'recovery_required',
            'summary' => $summary,
            'platform' => PHP_OS_FAMILY.' / Process Supervisor',
            'timezone' => $this->applicationTimezone->effective(),
            'heartbeat_at' => null,
            'reason_code' => $reasonCode,
            'groups' => [],
        ];
        $this->diagnostics->record($projection);

        return $projection;
    }

    /** @param array<string,mixed> $status @param array<string,mixed>|null $desired */
    private function heartbeatState(array $status, ?array $desired): string
    {
        $desiredGroups = is_array($desired['groups'] ?? null) ? $desired['groups'] : [];
        $active = collect($desiredGroups)->contains(
            static fn (mixed $group): bool => is_array($group)
                && (int) ($group['desired_processes'] ?? 0) > 0,
        );
        if (! $active) {
            return 'inactive';
        }

        $startupGrace = max(1, (int) config('process-supervisor.startup_grace_seconds', 10));
        $desiredAge = null;
        if (is_string($desired['generated_at'] ?? null)) {
            try {
                $desiredAge = Carbon::parse($desired['generated_at'])->diffInRealSeconds(now(), absolute: true);
            } catch (Throwable) {
                $desiredAge = null;
            }
        }
        $desiredRevision = is_int($desired['revision'] ?? null) ? $desired['revision'] : null;
        $statusRevision = is_int($status['revision'] ?? null) ? $status['revision'] : null;
        $revisionPending = $desiredRevision !== null
            && ($statusRevision === null || $statusRevision < $desiredRevision);
        if ($revisionPending && $desiredAge !== null && $desiredAge <= $startupGrace) {
            return 'starting';
        }

        $heartbeat = $status['heartbeat_at'] ?? null;
        if (! is_string($heartbeat) || trim($heartbeat) === '') {
            return $desiredAge !== null && $desiredAge <= $startupGrace
                ? 'starting'
                : 'stale';
        }

        try {
            $heartbeatAge = Carbon::parse($heartbeat)->diffInRealSeconds(now(), absolute: true);
        } catch (Throwable) {
            return 'stale';
        }

        $threshold = max(1, (int) config('process-supervisor.heartbeat_stale_seconds', 10));

        return $heartbeatAge > $threshold ? 'stale' : 'fresh';
    }

    /** @param array<string,mixed> $status */
    private function summary(array $status): string
    {
        $summary = $status['summary'] ?? $status['state'] ?? 'stopped';
        if (! is_string($summary) || ! in_array($summary, ['disabled', 'stopped', 'starting', 'ready', 'degraded', 'stopping', 'recovery_required', 'unsupported'], true)) {
            return 'recovery_required';
        }

        return $summary;
    }
}
