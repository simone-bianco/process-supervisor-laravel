<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Support;

use Illuminate\Support\Facades\Log;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\DiagnosticsSink;

final readonly class LogDiagnosticsSink implements DiagnosticsSink
{
    public function record(array $entry): void
    {
        $level = is_string($entry['level'] ?? null) ? $entry['level'] : 'warning';
        $message = is_string($entry['message'] ?? null)
            ? $entry['message']
            : 'Process Supervisor diagnostic event.';
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        $context['event'] = $entry['event'] ?? null;
        $context['component'] = $entry['component'] ?? null;

        match ($level) {
            'error', 'critical', 'alert', 'emergency' => Log::error($message, $context),
            'info', 'notice' => Log::info($message, $context),
            default => Log::warning($message, $context),
        };
    }
}
