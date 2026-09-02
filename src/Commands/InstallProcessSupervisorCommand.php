<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Commands;

use Illuminate\Console\Command;
use SimoneBianco\ProcessSupervisorLaravel\Install\ComposerScriptsInstaller;
use SimoneBianco\ProcessSupervisorLaravel\Install\EnvironmentFileEditor;
use SimoneBianco\ProcessSupervisorLaravel\Install\PythonEngineInstaller;
use Symfony\Component\Process\Process;
use Throwable;

final class InstallProcessSupervisorCommand extends Command
{
    protected $signature = 'process-supervisor:install';

    protected $description = 'Interactively install and configure Process Supervisor';

    public function handle(
        PythonEngineInstaller $python,
        EnvironmentFileEditor $environment,
        ComposerScriptsInstaller $composerScripts,
    ): int {
        $this->newLine();
        $this->components->info('Process Supervisor installer');
        $this->line('This installer configures the Laravel control plane and a dedicated Python runtime.');
        $this->line('No application file is modified without an explicit confirmation.');
        $this->newLine();

        try {
            if ($this->confirm('Publish process-supervisor.php so the application can customize runtime behavior?', true)) {
                $this->call('vendor:publish', ['--tag' => 'process-supervisor-config']);
            }

            if ($this->confirm('Add recommended Process Supervisor defaults to .env when the keys are missing?', false)) {
                $added = $environment->addMissing(base_path('.env'), [
                    'PROCESS_SUPERVISOR_ENABLED' => 'false',
                    'PROCESS_SUPERVISOR_REVERB_PORT' => '8080',
                ]);
                $this->line($added === []
                    ? 'Environment keys already exist; nothing was changed.'
                    : 'Added: '.implode(', ', $added));
            }

            if ($this->confirm('Install or synchronize the dedicated Python engine now?', true)) {
                $result = $python->sync();
                $this->info('Python engine ready at '.$result['python']);
            }

            if ($this->confirm('Add automatic Python synchronization to Composer post-install and post-update hooks?', true)) {
                $events = $composerScripts->install(base_path('composer.json'));
                $this->line($events === []
                    ? 'Composer hooks already contain Process Supervisor synchronization.'
                    : 'Updated Composer hooks: '.implode(', ', $events));
            }

            if (is_file(base_path('package.json')) && $this->confirm(
                'Install the optional reusable Vue UI package with npm now?',
                false,
            )) {
                $package = config('process-supervisor.vue_package', 'github:simone-bianco/process-supervisor-vue');
                if (! is_string($package) || trim($package) === '') {
                    throw new \RuntimeException('The Vue package source is not configured.');
                }
                $npm = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
                $process = new Process([$npm, 'install', $package], base_path(), null, null, 180);
                $process->run();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException('npm could not install the Process Supervisor Vue package. '.trim($process->getErrorOutput()));
                }
                $this->info('Vue UI package installed.');
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Process Supervisor installation completed.');
        $this->line('The tool is disabled by default. Enable it only after reviewing your published configuration.');

        return self::SUCCESS;
    }
}
