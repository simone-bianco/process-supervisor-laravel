<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use JsonException;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\AvailabilityProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\SupervisorControlClient;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;
use Throwable;

final readonly class SupervisorRuntimeService
{
    public function __construct(
        private SupervisorRuntimePaths $paths,
        private SupervisorControlLock $controlLock,
        private SupervisorControlClient $python,
        private SupervisorManifestFactory $manifests,
        private AvailabilityProvider $availability,
        private SupervisorReconciliationMarker $reconciliation,
        private SupervisorDiagnosticsRecorder $diagnostics,
        private ReverbPortGuard $reverbPortGuard,
        private SupervisorStatusPresenter $presenter,
    ) {}

    /** @return array<string, mixed> */
    public function pageStatus(): array
    {
        return $this->safeStatus();
    }

    /** @return array<string, mixed> */
    public function status(bool $requireEnabled = true): array
    {
        if ($requireEnabled && ! $this->availability->enabled()) {
            throw new SupervisorRuntimeException('SUPERVISOR_DISABLED', 'The Python process supervisor is disabled.', 404);
        }

        $status = $this->python->run('status')['status'] ?? [];
        if (! is_array($status)) {
            throw new SupervisorRuntimeException('SUPERVISOR_RESPONSE_INVALID', 'The process supervisor status is invalid.', 502);
        }

        return $this->present($status);
    }

    /** @return array{warning:string|null,status:array<string,mixed>} */
    public function synchronizeAvailability(bool $enabled): array
    {
        if ($enabled) {
            try {
                $this->applyAvailabilityManifest(true);
                $this->reconciliation->clear();

                return ['warning' => null, 'status' => $this->safeStatus()];
            } catch (Throwable $exception) {
                report($exception);

                return [
                    'warning' => 'The supervisor setting was saved, but its runtime state could not be synchronized.',
                    'status' => $this->safeStatus(),
                ];
            }
        }

        $applyFailed = false;
        $shutdownFailed = false;
        $shutdownRejected = false;
        $shutdownRecoveryRequired = false;

        try {
            $this->applyAvailabilityManifest(false);
        } catch (Throwable $exception) {
            report($exception);
            $applyFailed = true;
        }

        try {
            $result = $this->python->run('shutdown');
            $shutdownRecoveryRequired = ($result['status'] ?? null) === 'recovery_required';
        } catch (Throwable $exception) {
            report($exception);
            $shutdownFailed = true;
            $shutdownRejected = $exception instanceof SupervisorRuntimeException
                && $exception->errorCode === 'CONTROL_REJECTED';
        }

        $status = $this->safeStatus();
        if ($applyFailed || $shutdownFailed || $shutdownRecoveryRequired) {
            $reasonCode = $shutdownRejected
                ? 'disable_gate_unconfirmed'
                : ($shutdownRecoveryRequired ? 'disable_shutdown_recovery_required' : 'disable_reconciliation_uncertain');
            $this->reconciliation->mark($reasonCode);
            $status = $this->recoveryProjection($status, $reasonCode);

            return [
                'warning' => 'The supervisor was disabled, but one or more owned processes or runtime records may still require recovery.',
                'status' => $status,
            ];
        }

        $this->reconciliation->clear();

        return ['warning' => null, 'status' => $status];
    }

    private function applyAvailabilityManifest(bool $enabled): void
    {
        $this->controlLock->run(function () use ($enabled): void {
            $current = $this->readDesired();
            $revision = is_int($current['revision'] ?? null) ? $current['revision'] + 1 : 1;
            $manifest = $this->manifests->make($enabled, $revision, $current);
            $this->python->run('apply-desired', $manifest);
        });
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private function recoveryProjection(array $status, string $reasonCode): array
    {
        $status['enabled'] = false;
        $status['can_recover'] = true;
        $status['summary'] = 'recovery_required';
        $status['reason_code'] = $reasonCode;

        $this->diagnostics->record($status);

        return $status;
    }

    /** @return array<string, mixed> */
    public function mutate(string $groupId, string $action): array
    {
        if (! $this->availability->enabled()) {
            throw new SupervisorRuntimeException('SUPERVISOR_DISABLED', 'The Python process supervisor is disabled.', 404);
        }
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new SupervisorRuntimeException('SUPERVISOR_ACTION_INVALID', 'Unsupported supervisor action.', 422);
        }

        $this->controlLock->run(function () use ($groupId, $action): void {
            $this->assertEnabled();
            $definition = collect($this->manifests->definitions())->firstWhere('id', $groupId);
            if (! is_array($definition)) {
                throw new SupervisorRuntimeException('SUPERVISOR_GROUP_UNKNOWN', 'The requested process group is not configured.', 404);
            }
            if ($action !== 'stop') {
                $this->assertRecoveryNotRequired();
            }
            if ($action === 'start' && ($definition['group']['kind'] ?? null) === 'reverb') {
                $this->reverbPortGuard->assertAvailable();
            }

            $current = $this->readDesired();
            $revision = is_int($current['revision'] ?? null) ? $current['revision'] + 1 : 1;
            $preserveCurrent = $action === 'stop' && is_array($current) && ($current['enabled'] ?? false) === true;
            $manifest = $preserveCurrent
                ? [...$current, 'revision' => $revision, 'generated_at' => now()->toISOString()]
                : $this->manifests->make(true, $revision, $current);

            $found = false;
            foreach ($manifest['groups'] as &$group) {
                if (($group['id'] ?? null) !== $groupId) {
                    continue;
                }
                $found = true;
                $currentDesired = is_int($group['desired_processes'] ?? null) ? $group['desired_processes'] : 0;
                if ($action === 'start') {
                    $group['desired_processes'] = $definition['default_processes'];
                } elseif ($action === 'stop') {
                    $group['desired_processes'] = 0;
                } elseif ($action === 'restart') {
                    if ($currentDesired < 1) {
                        throw new SupervisorRuntimeException('SUPERVISOR_GROUP_STOPPED', 'A stopped process group cannot be restarted.', 409);
                    }
                    $group['generation'] = (int) ($group['generation'] ?? 0) + 1;
                }
                break;
            }
            unset($group);
            if (! $found) {
                throw new SupervisorRuntimeException('SUPERVISOR_GROUP_UNKNOWN', 'The requested process group is not configured.', 404);
            }

            $applied = $this->python->run('apply-desired', $manifest);
            $active = array_any($manifest['groups'], static fn (array $group): bool => ($group['desired_processes'] ?? 0) > 0);
            $spawnGate = is_string($applied['spawn_gate'] ?? null) ? $applied['spawn_gate'] : 'enabled';
            if ($active && $spawnGate === 'enabled') {
                $this->python->run('ensure-running');
            }
        });

        return $this->status();
    }

    /** @return array<string, mixed> */
    public function startAll(): array
    {
        if (! $this->availability->enabled()) {
            throw new SupervisorRuntimeException('SUPERVISOR_DISABLED', 'The Python process supervisor is disabled.', 404);
        }
        $this->controlLock->run(function (): void {
            $this->assertEnabled();
            $this->assertRecoveryNotRequired();
            $current = $this->readDesired();
            $revision = is_int($current['revision'] ?? null) ? $current['revision'] + 1 : 1;
            $manifest = $this->manifests->make(true, $revision, $current);
            $definitions = collect($this->manifests->definitions())->keyBy('id');
            $changed = false;

            foreach ($manifest['groups'] as &$group) {
                if (($group['desired_processes'] ?? 0) > 0) {
                    continue;
                }
                $definition = $definitions->get($group['id'] ?? null);
                if (! is_array($definition)) {
                    continue;
                }
                $desired = max(0, min(
                    (int) ($definition['default_processes'] ?? 0),
                    (int) ($definition['max_processes'] ?? 0),
                ));
                if ($desired < 1) {
                    continue;
                }
                if (($definition['group']['kind'] ?? null) === 'reverb') {
                    $this->reverbPortGuard->assertAvailable();
                }
                $group['desired_processes'] = $desired;
                $changed = true;
            }
            unset($group);

            if (! $changed) {
                return;
            }

            $this->python->run('apply-desired', $manifest);
            $this->python->run('ensure-running');
        });

        return $this->status();
    }

    public function desiredProcesses(string $groupId): int
    {
        $desired = $this->readDesired();
        foreach ((array) ($desired['groups'] ?? []) as $group) {
            if (is_array($group) && ($group['id'] ?? null) === $groupId) {
                return max(0, (int) ($group['desired_processes'] ?? 0));
            }
        }

        return 0;
    }

    public function isQuiescent(): bool
    {
        return $this->controlLock->run(function (): bool {
            $status = $this->safeStatus();
            if (! in_array($status['summary'] ?? null, ['stopped', 'disabled'], true)) {
                return false;
            }
            foreach ((array) ($status['groups'] ?? []) as $group) {
                if (! is_array($group) || (int) ($group['desired_processes'] ?? 0) > 0) {
                    return false;
                }
                foreach ((array) ($group['instances'] ?? []) as $instance) {
                    if (is_array($instance) && ! in_array($instance['state'] ?? null, ['stopped'], true)) {
                        return false;
                    }
                }
            }

            return true;
        });
    }

    private function assertEnabled(): void
    {
        if (! $this->availability->enabled()) {
            throw new SupervisorRuntimeException('SUPERVISOR_DISABLED', 'The Python process supervisor is disabled.', 404);
        }
    }

    private function assertRecoveryNotRequired(): void
    {
        $status = $this->safeStatus();
        if (($status['summary'] ?? null) === 'recovery_required') {
            throw new SupervisorRuntimeException(
                'SUPERVISOR_RECOVERY_REQUIRED',
                'Recover the Process Supervisor runtime before starting managed services.',
                409,
            );
        }
    }

    /** @return array<string, mixed> */
    public function recover(): array
    {
        return $this->controlLock->run(function (): array {
            $eligibility = $this->safeStatus();
            if (($eligibility['summary'] ?? null) !== 'recovery_required') {
                throw new SupervisorRuntimeException(
                    'SUPERVISOR_RECOVERY_NOT_REQUIRED',
                    'The Process Supervisor runtime is not awaiting recovery.',
                    409,
                );
            }

            $this->reconciliation->mark('recovery_in_progress');
            try {
                $gate = $this->python->run('disable-gate');
                if (($gate['spawn_gate'] ?? null) !== 'disabled') {
                    throw new SupervisorRuntimeException(
                        'SUPERVISOR_RECOVERY_GATE_RESPONSE_INVALID',
                        'Process Supervisor recovery could not confirm the closed spawn gate.',
                        503,
                    );
                }
            } catch (Throwable $exception) {
                $this->reconciliation->mark('recovery_gate_close_failed');
                $this->diagnostics->recordControlFailure(
                    'process_supervisor.recovery_gate_close_failed',
                    'Process Supervisor recovery could not close the spawn gate before authority repair.',
                    [
                        'reason_code' => 'recovery_gate_close_failed',
                        'phase' => 'disable_gate',
                        'exception' => $exception::class,
                    ],
                );

                throw new SupervisorRuntimeException(
                    'SUPERVISOR_RECOVERY_GATE_CLOSE_FAILED',
                    'Runtime recovery could not prove the spawn gate is closed.',
                    503,
                );
            }

            try {
                $current = $this->readDesired();
                $revision = is_int($current['revision'] ?? null) ? $current['revision'] + 1 : 1;
                $manifest = $this->manifests->make(false, $revision, $current);
                $this->python->run('apply-desired', $manifest);
            } catch (Throwable $exception) {
                $this->diagnostics->recordControlFailure(
                    'process_supervisor.recovery_quiesce_failed',
                    'Process Supervisor recovery could not publish a disabled desired state before salvage.',
                    [
                        'reason_code' => 'recovery_quiesce_failed',
                        'phase' => 'apply_disabled_desired',
                        'exception' => $exception::class,
                    ],
                );
            }

            try {
                $this->python->run('shutdown');
            } catch (Throwable $exception) {
                $this->diagnostics->recordControlFailure(
                    'process_supervisor.recovery_quiesce_failed',
                    'Process Supervisor recovery could not complete the pre-recovery shutdown phase.',
                    [
                        'reason_code' => 'recovery_quiesce_failed',
                        'phase' => 'shutdown',
                        'exception' => $exception::class,
                    ],
                );
            }

            $result = $this->python->run('recover');
            if (! in_array($result['status'] ?? null, ['recovered', 'already_clean'], true)) {
                throw new SupervisorRuntimeException('SUPERVISOR_RECOVERY_RESPONSE_INVALID', 'Supervisor recovery did not prove a clean runtime.', 503);
            }

            $this->reconciliation->mark('recovery_desired_normalization_pending');
            try {
                $this->normalizeRecoveredDesiredLocked();
            } catch (Throwable $exception) {
                $this->reconciliation->mark('recovery_desired_normalization_failed');
                $this->diagnostics->recordControlFailure(
                    'process_supervisor.recovery_normalization_failed',
                    'Process Supervisor recovery could not normalize desired state after proving runtime quiescence.',
                    [
                        'reason_code' => 'recovery_desired_normalization_failed',
                        'enabled' => $this->availability->enabled(),
                        'exception' => $exception::class,
                    ],
                );

                throw new SupervisorRuntimeException(
                    'SUPERVISOR_RECOVERY_NORMALIZATION_FAILED',
                    'Runtime recovery succeeded, but desired state could not be normalized. Recovery remains required.',
                    503,
                );
            }

            $this->reconciliation->clear();

            return ['result' => $result, 'status' => $this->safeStatus()];
        });
    }

    private function normalizeRecoveredDesiredLocked(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $enabled = $this->availability->enabled();
            $current = $this->readDesired();
            $revision = is_int($current['revision'] ?? null) ? $current['revision'] + 1 : 1;
            $manifest = $this->manifests->make($enabled, $revision, $current);
            foreach ($manifest['groups'] as &$group) {
                $group['desired_processes'] = 0;
            }
            unset($group);

            $applied = $this->python->run('apply-desired', $manifest);
            $expectedGate = $enabled ? 'enabled' : 'disabled';
            if (($applied['spawn_gate'] ?? null) !== $expectedGate) {
                throw new SupervisorRuntimeException(
                    'SUPERVISOR_RECOVERY_NORMALIZATION_RESPONSE_INVALID',
                    'The normalized desired state did not confirm the expected spawn gate.',
                    503,
                );
            }
            if ($this->availability->enabled() === $enabled) {
                return;
            }
        }

        throw new SupervisorRuntimeException(
            'SUPERVISOR_RECOVERY_AVAILABILITY_UNSTABLE',
            'Process Supervisor availability changed repeatedly during recovery normalization.',
            503,
        );
    }

    /** @return array<string, mixed> */
    private function present(array $status): array
    {
        return $this->presenter->present($status, $this->readDesired());
    }

    /** @return array<string, mixed> */
    private function safeStatus(): array
    {
        $enabled = $this->availability->enabled();
        $reconciliationReason = $this->reconciliation->reason();
        if ($reconciliationReason !== null) {
            return $this->fallbackStatus($enabled, 'recovery_required', $reconciliationReason);
        }
        if (! $enabled && ! is_file($this->paths->desired())) {
            try {
                return $this->present(['state' => 'stopped']);
            } catch (Throwable) {
                return $this->fallbackStatus(false, 'stopped', null);
            }
        }

        try {
            return $this->status(requireEnabled: false);
        } catch (Throwable $exception) {
            $this->diagnostics->recordControlFailure(
                'process_supervisor.status_projection_failed',
                'Process Supervisor status could not be projected and was reduced to a fail-closed fallback.',
                [
                    'reason_code' => 'status_projection_failed',
                    'exception' => $exception::class,
                    'error_code' => $exception instanceof SupervisorRuntimeException ? $exception->errorCode : null,
                ],
            );
            $corruptState = $exception instanceof SupervisorRuntimeException
                && in_array($exception->errorCode, [
                    'INVALID_RUNTIME_STATE',
                    'SUPERVISOR_STATE_INVALID',
                    'SUPERVISOR_STATE_IDENTITY_MISMATCH',
                ], true);

            return $this->fallbackStatus(
                $enabled,
                $corruptState ? 'recovery_required' : ($enabled ? 'unsupported' : 'recovery_required'),
                $corruptState ? 'runtime_state_corrupt' : 'runtime_unavailable',
            );
        }
    }

    /** @return array<string, mixed> */
    private function fallbackStatus(bool $enabled, string $summary, ?string $reasonCode): array
    {
        return $this->presenter->fallback($enabled, $summary, $reasonCode);
    }

    /** @return array<string,mixed>|null */
    private function readDesired(): ?array
    {
        $desired = $this->readJsonFile($this->paths->desired(), required: false);
        if ($desired === null) {
            return null;
        }
        if (($desired['schema_version'] ?? null) !== 1 || ($desired['installation_id'] ?? null) !== $this->paths->installationId()) {
            throw new SupervisorRuntimeException('SUPERVISOR_STATE_IDENTITY_MISMATCH', 'The process supervisor desired state belongs to a different runtime or schema.', 503);
        }

        return $desired;
    }

    /** @return array<string,mixed>|null */
    private function readJsonFile(string $path, bool $required): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                if (! is_file($path) && ! $required) {
                    return null;
                }
                usleep(10_000);

                continue;
            }
            try {
                $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new SupervisorRuntimeException('SUPERVISOR_STATE_INVALID', 'The process supervisor state is invalid.', 503);
            }
            if (! is_array($decoded)) {
                throw new SupervisorRuntimeException('SUPERVISOR_STATE_INVALID', 'The process supervisor state is invalid.', 503);
            }

            return $decoded;
        }
        throw new SupervisorRuntimeException('SUPERVISOR_STATE_UNAVAILABLE', 'The process supervisor state could not be read.', 503);
    }
}
