<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use RuntimeException;

final readonly class SupervisorPythonExecutableResolver
{
    public function resolve(): string
    {
        $configured = config('process-supervisor.python.executable');
        if (is_string($configured) && trim($configured) !== '') {
            return $this->verified(trim($configured));
        }

        $venv = config('process-supervisor.python.venv');
        if (! is_string($venv) || trim($venv) === '') {
            throw new RuntimeException('The Process Supervisor Python venv is not configured.');
        }
        $candidate = rtrim($venv, '\\/').DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows'
            ? 'Scripts'.DIRECTORY_SEPARATOR.'python.exe'
            : 'bin'.DIRECTORY_SEPARATOR.'python');

        return $this->verified($candidate);
    }

    private function verified(string $candidate): string
    {
        $resolved = realpath($candidate);
        if (! is_string($resolved) || ! is_file($resolved)) {
            throw new RuntimeException('The Process Supervisor Python executable is unavailable.');
        }

        return $resolved;
    }
}
