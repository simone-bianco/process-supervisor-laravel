<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Commands;

use Illuminate\Console\Command;
use SimoneBianco\ProcessSupervisorLaravel\Install\PythonEngineInstaller;
use Throwable;

final class SyncPythonEngineCommand extends Command
{
    protected $signature = 'process-supervisor:sync-python';

    protected $description = 'Create or synchronize the dedicated Process Supervisor Python runtime';

    public function handle(PythonEngineInstaller $installer): int
    {
        try {
            $result = $installer->sync();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Process Supervisor Python engine is ready.');
        $this->line('Python: '.$result['python']);
        $this->line('Source: '.$result['package']);

        return self::SUCCESS;
    }
}
