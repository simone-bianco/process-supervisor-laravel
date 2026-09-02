<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use SimoneBianco\ProcessSupervisorLaravel\Events\SupervisorReverbProbe;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;
use SimoneBianco\ProcessSupervisorLaravel\Jobs\SupervisorQueueProbeJob;

final readonly class SupervisorProbeService
{
    public function __construct(
        private SupervisorRuntimeService $runtime,
        private SupervisorManifestFactory $manifests,
        private BusDispatcher $bus,
        private EventDispatcher $events,
    ) {}

    /** @return array{kind:string,probe_id:string} */
    public function run(string $groupId, string $probeId): array
    {
        $status = $this->runtime->status();
        $group = collect($status['groups'] ?? [])->firstWhere('id', $groupId);
        if (! is_array($group)) {
            throw new SupervisorRuntimeException('SUPERVISOR_GROUP_UNKNOWN', 'The requested process group is not configured.', 404);
        }
        $kind = $group['kind'] ?? null;
        if (! in_array($kind, ['queue_once', 'reverb'], true)) {
            throw new SupervisorRuntimeException(
                'SUPERVISOR_PROBE_UNSUPPORTED',
                'This process group does not expose a probe.',
                422,
            );
        }
        if (($group['can_probe'] ?? false) !== true) {
            throw new SupervisorRuntimeException(
                'SUPERVISOR_PROBE_UNAVAILABLE',
                'Recover the runtime and start the process group before running its probe.',
                409,
            );
        }

        return $kind === 'queue_once'
            ? $this->queueProbe($groupId, $probeId)
            : $this->reverbProbe($probeId);
    }

    /** @return array{kind:'queue',probe_id:string} */
    private function queueProbe(string $groupId, string $probeId): array
    {
        $definition = collect($this->manifests->definitions())->firstWhere('id', $groupId);
        $queue = is_array($definition) ? ($definition['group']['queue'] ?? null) : null;
        $connection = is_array($queue) && is_string($queue['connection'] ?? null)
            ? $queue['connection']
            : null;
        $queueName = is_array($queue)
            && is_array($queue['queues'] ?? null)
            && is_string($queue['queues'][0] ?? null)
            ? $queue['queues'][0]
            : null;
        if ($connection === null || $queueName === null) {
            throw new SupervisorRuntimeException('SUPERVISOR_QUEUE_PROBE_CONFIG_INVALID', 'The queue probe configuration is invalid.', 500);
        }

        $job = (new SupervisorQueueProbeJob($probeId, $groupId))
            ->onConnection($connection)
            ->onQueue($queueName);
        $this->bus->dispatch($job);

        return ['kind' => 'queue', 'probe_id' => $probeId];
    }

    /** @return array{kind:'reverb',probe_id:string} */
    private function reverbProbe(string $probeId): array
    {
        $this->events->dispatch(new SupervisorReverbProbe($probeId));

        return ['kind' => 'reverb', 'probe_id' => $probeId];
    }
}
