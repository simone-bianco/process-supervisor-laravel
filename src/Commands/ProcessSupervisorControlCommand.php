<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;
use SimoneBianco\ProcessSupervisorLaravel\Services\SupervisorProbeService;
use SimoneBianco\ProcessSupervisorLaravel\Services\SupervisorReverbClientProjection;
use SimoneBianco\ProcessSupervisorLaravel\Services\SupervisorRuntimeService;
use Throwable;

final class ProcessSupervisorControlCommand extends Command
{
    protected $signature = 'process-supervisor:control
        {action : status, recover, start, stop, restart, start-all, probe or client}
        {group? : Configured group id for start, stop, restart or probe}';

    protected $description = 'Inspect or control Process Supervisor through Laravel application authority';

    public function handle(
        SupervisorRuntimeService $runtime,
        SupervisorProbeService $probes,
        SupervisorReverbClientProjection $reverbClient,
    ): int {
        $action = strtolower(trim((string) $this->argument('action')));
        $group = $this->argument('group');
        $group = is_string($group) ? trim($group) : null;

        try {
            $result = match ($action) {
                'status' => $runtime->status(),
                'recover' => $runtime->recover(),
                'start-all' => $runtime->startAll(),
                'start', 'stop', 'restart' => $this->groupAction($runtime, $action, $group),
                'probe' => $this->probe($probes, $group),
                'client' => $this->client($reverbClient),
                default => throw new \InvalidArgumentException('Unsupported Process Supervisor action.'),
            };
            $this->line($this->json($result));

            return self::SUCCESS;
        } catch (SupervisorRuntimeException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Process Supervisor command failed.');

            return self::FAILURE;
        }
    }

    /** @return array<string,mixed> */
    private function groupAction(SupervisorRuntimeService $runtime, string $action, ?string $group): array
    {
        if ($group === null || $group === '') {
            throw new \InvalidArgumentException("Action {$action} requires a configured group id.");
        }

        return $runtime->mutate($group, $action);
    }

    /** @return array<string,mixed> */
    private function probe(SupervisorProbeService $probes, ?string $group): array
    {
        if ($group === null || $group === '') {
            throw new \InvalidArgumentException('Action probe requires a configured group id.');
        }

        return $probes->run($group, (string) Str::uuid());
    }

    /** @return array<string,mixed> */
    private function client(SupervisorReverbClientProjection $projection): array
    {
        $client = $projection->get();
        if ($client === null) {
            throw new SupervisorRuntimeException(
                'SUPERVISOR_REVERB_CLIENT_UNAVAILABLE',
                'Public Reverb client configuration is unavailable.',
                503,
            );
        }

        return $client;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode Process Supervisor response.', previous: $exception);
        }
    }
}
