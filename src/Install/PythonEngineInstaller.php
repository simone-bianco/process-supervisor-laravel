<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Install;

use RuntimeException;
use SimoneBianco\ProcessSupervisorLaravel\Services\SupervisorPythonExecutableResolver;
use Symfony\Component\Process\Process;

final readonly class PythonEngineInstaller
{
    public function __construct(private SupervisorPythonExecutableResolver $resolver) {}

    /** @return array{python:string,package:string,uv:string} */
    public function sync(): array
    {
        $uv = config('process-supervisor.python.uv_binary', 'uv');
        $venv = config('process-supervisor.python.venv');
        $package = config('process-supervisor.python.package');
        if (! is_string($uv) || trim($uv) === '') {
            throw new RuntimeException('The Process Supervisor uv executable is not configured.');
        }
        if (! is_string($venv) || trim($venv) === '') {
            throw new RuntimeException('The Process Supervisor Python venv path is not configured.');
        }
        if (! is_string($package) || trim($package) === '') {
            throw new RuntimeException('The Process Supervisor Python package source is not configured.');
        }

        $this->mustRun([$uv, '--version'], 'uv is required to install the Process Supervisor Python engine.');
        $python = $this->pythonPath($venv);
        if (! is_file($python)) {
            $this->mustRun([$uv, 'venv', $venv], 'Unable to create the Process Supervisor Python venv.');
        }
        $this->mustRun(
            [$uv, 'pip', 'install', '--python', $python, '--upgrade', $package],
            'Unable to install the Process Supervisor Python engine.',
            180,
        );
        $this->mustRun(
            [$python, '-c', 'import py_laravel_supervisor; print(py_laravel_supervisor.__file__)'],
            'The Process Supervisor Python engine was installed but could not be imported.',
        );
        $doctor = $this->mustRun(
            [
                $python,
                '-m',
                'py_laravel_supervisor.cli',
                'doctor',
                '--runtime-root',
                (string) config('process-supervisor.runtime_root'),
            ],
            'The Process Supervisor Python engine is installed but the runtime doctor rejected this host.',
        );
        $decoded = json_decode($doctor, true);
        if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('The Process Supervisor runtime doctor returned an invalid response.');
        }

        return ['python' => $this->resolver->resolve(), 'package' => $package, 'uv' => $uv];
    }

    private function pythonPath(string $venv): string
    {
        return rtrim($venv, '\\/').DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows'
            ? 'Scripts'.DIRECTORY_SEPARATOR.'python.exe'
            : 'bin'.DIRECTORY_SEPARATOR.'python');
    }

    /** @param list<string> $command */
    private function mustRun(array $command, string $message, int $timeout = 30): string
    {
        $process = new Process($command, base_path(), null, null, $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($message.' '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }
}
