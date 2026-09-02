<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupervisorReverbProbe implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const string CHANNEL = 'process-supervisor.probe';

    public const string EVENT = 'process-supervisor.reverb-probe';

    public function __construct(public readonly string $probeId) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return self::EVENT;
    }

    /** @return array{probe_id:string} */
    public function broadcastWith(): array
    {
        return ['probe_id' => $this->probeId];
    }
}
