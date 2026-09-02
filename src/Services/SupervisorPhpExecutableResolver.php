<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;
use Symfony\Component\Process\Process;

final class SupervisorPhpExecutableResolver
{
    /** @var array<string,bool> */
    private array $verified = [];

    public function resolve(): string
    {
        $configured = config('process-supervisor.php_binary');
        if (is_string($configured) && trim($configured) !== '') {
            $resolved = $this->canonical(trim($configured));
            if ($resolved === null || ! $this->isCli($resolved)) {
                throw new SupervisorRuntimeException(
                    'SUPERVISOR_PHP_NOT_CLI',
                    'The configured Process Supervisor PHP executable is not a usable CLI binary.',
                    503,
                );
            }

            return $resolved;
        }

        foreach ($this->candidates() as $candidate) {
            $resolved = $this->canonical($candidate);
            if ($resolved !== null && $this->isCli($resolved)) {
                return $resolved;
            }
        }

        throw new SupervisorRuntimeException(
            'SUPERVISOR_PHP_UNAVAILABLE',
            'A CLI PHP executable could not be resolved for Process Supervisor.',
            503,
        );
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $binary = PHP_BINARY;
        $directory = dirname($binary);
        $candidates = [$binary];
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = $directory.DIRECTORY_SEPARATOR.'php.exe';
        } else {
            $candidates[] = $directory.DIRECTORY_SEPARATOR.'php';
        }
        if (defined('PHP_BINDIR') && is_string(PHP_BINDIR) && PHP_BINDIR !== '') {
            $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        }

        return array_values(array_unique($candidates));
    }

    private function canonical(string $candidate): ?string
    {
        $resolved = realpath($candidate);
        if (! is_string($resolved) || $resolved === '' || ! is_file($resolved)) {
            return null;
        }
        if (! $this->absolute($resolved)) {
            return null;
        }

        return $resolved;
    }

    private function isCli(string $binary): bool
    {
        if (array_key_exists($binary, $this->verified)) {
            return $this->verified[$binary];
        }

        try {
            $process = new Process([$binary, '-r', 'echo PHP_SAPI;'], base_path(), timeout: 5);
            $process->run();
            $valid = $process->isSuccessful() && trim($process->getOutput()) === 'cli';
        } catch (\Throwable) {
            $valid = false;
        }

        return $this->verified[$binary] = $valid;
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR);
    }
}
