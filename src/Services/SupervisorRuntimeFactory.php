<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Contracts\AvailabilityProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\DiagnosticsSink;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\ReverbPortProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\SupervisorControlClient;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\TimezoneProvider;

final readonly class SupervisorRuntimeFactory
{
    public function __construct(
        private SupervisorPythonExecutableResolver $python,
        private SupervisorManifestFactory $manifests,
        private SupervisorCronExpression $cron,
        private SupervisorPhpExecutableResolver $php,
        private DiagnosticsSink $diagnosticsSink,
    ) {}

    public function phpExecutable(): string
    {
        return $this->php->resolve();
    }

    public function forContext(SupervisorRuntimeContext $context, ?SupervisorControlClient $control = null): SupervisorRuntimeService
    {
        $paths = new SupervisorRuntimePaths($context);
        $availability = new ContextAvailabilityProvider;
        $timezone = new ContextTimezoneProvider($context->timezone);
        $port = new ContextReverbPortProvider($context->reverbPort);
        $diagnostics = new SupervisorDiagnosticsRecorder($paths, $port, $this->diagnosticsSink);
        $manifest = $this->manifests->forContext($context);
        $presenter = new SupervisorStatusPresenter($manifest, $availability, $this->cron, $timezone, $diagnostics);

        return new SupervisorRuntimeService(
            $paths,
            new SupervisorControlLock($paths),
            $control ?? new SupervisorPythonBridge($this->python, $paths),
            $manifest,
            $availability,
            new SupervisorReconciliationMarker($paths),
            $diagnostics,
            new ReverbPortGuard($diagnostics, $port),
            $presenter,
        );
    }
}

final readonly class ContextAvailabilityProvider implements AvailabilityProvider
{
    public function enabled(): bool
    {
        return true;
    }
}

final readonly class ContextTimezoneProvider implements TimezoneProvider
{
    public function __construct(private string $timezone)
    {
    }

    public function effective(): string
    {
        return $this->timezone;
    }
}

final readonly class ContextReverbPortProvider implements ReverbPortProvider
{
    public function __construct(private ?int $port)
    {
    }

    public function effective(): int
    {
        return $this->port ?? (int) config('process-supervisor.reverb.port', 8080);
    }
}
