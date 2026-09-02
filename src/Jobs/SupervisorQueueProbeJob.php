<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\DiagnosticsSink;

final class SupervisorQueueProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $probeId,
        public readonly string $groupId,
    ) {}

    public function handle(DiagnosticsSink $sink): void
    {
        $sink->record([
            'level' => 'info',
            'component' => 'process-supervisor.queue',
            'event' => 'process_supervisor.queue_probe',
            'message' => 'Process Supervisor queue probe executed.',
            'context' => [
                'probe_id' => $this->probeId,
                'group_id' => $this->groupId,
            ],
        ]);
    }
}
