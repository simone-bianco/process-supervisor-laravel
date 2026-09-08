<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel;

use Illuminate\Support\ServiceProvider;
use SimoneBianco\ProcessSupervisorLaravel\Commands\InstallProcessSupervisorCommand;
use SimoneBianco\ProcessSupervisorLaravel\Commands\ProcessSupervisorControlCommand;
use SimoneBianco\ProcessSupervisorLaravel\Commands\SyncPythonEngineCommand;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\ArtisanServiceProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\AvailabilityProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\DiagnosticsSink;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\ReverbPortProvider;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\SupervisorControlClient;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\TimezoneProvider;
use SimoneBianco\ProcessSupervisorLaravel\Services\SupervisorPythonBridge;
use SimoneBianco\ProcessSupervisorLaravel\Support\ConfigArtisanServiceProvider;
use SimoneBianco\ProcessSupervisorLaravel\Support\ConfigAvailabilityProvider;
use SimoneBianco\ProcessSupervisorLaravel\Support\ConfigReverbPortProvider;
use SimoneBianco\ProcessSupervisorLaravel\Support\ConfigTimezoneProvider;
use SimoneBianco\ProcessSupervisorLaravel\Support\LogDiagnosticsSink;

final class ProcessSupervisorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/process-supervisor.php', 'process-supervisor');

        $this->app->bindIf(AvailabilityProvider::class, ConfigAvailabilityProvider::class);
        $this->app->bindIf(ArtisanServiceProvider::class, ConfigArtisanServiceProvider::class);
        $this->app->bindIf(TimezoneProvider::class, ConfigTimezoneProvider::class);
        $this->app->bindIf(ReverbPortProvider::class, ConfigReverbPortProvider::class);
        $this->app->bindIf(DiagnosticsSink::class, LogDiagnosticsSink::class);
        $this->app->bind(SupervisorControlClient::class, SupervisorPythonBridge::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/process-supervisor.php' => config_path('process-supervisor.php'),
        ], 'process-supervisor-config');

        $port = $this->app->make(ReverbPortProvider::class)->effective();
        config()->set('reverb.servers.reverb.port', $port);
        config()->set('reverb.apps.apps.0.options.port', $port);
        config()->set('broadcasting.connections.reverb.options.port', $port);

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallProcessSupervisorCommand::class,
                SyncPythonEngineCommand::class,
                ProcessSupervisorControlCommand::class,
            ]);
        }
    }
}
