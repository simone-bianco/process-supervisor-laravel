<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use RuntimeException;

final readonly class SupervisorRuntimePaths
{
    public function __construct(private ?SupervisorRuntimeContext $context = null) {}

    public function root(): string
    {
        if ($this->context !== null) {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->context->runtimeRoot), DIRECTORY_SEPARATOR);
        }
        $configured = config('process-supervisor.runtime_root');
        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException('The Laravel supervisor runtime root is not configured.');
        }

        $path = $this->absolute($configured) ? $configured : base_path($configured);

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    public function desired(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'desired.json';
    }

    public function controlLock(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'laravel-control-plane.lock';
    }

    public function reconciliation(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'laravel-reconciliation.json';
    }

    public function events(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'events.json';
    }

    public function diagnosticsState(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'laravel-diagnostics-state.json';
    }

    public function diagnosticsLock(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'laravel-diagnostics.lock';
    }

    public function installationId(): string
    {
        $canonical = realpath($this->root()) ?: $this->root();
        $canonical = str_replace('\\', '/', $canonical);
        if (PHP_OS_FAMILY === 'Windows') {
            $canonical = strtolower($canonical);
        }

        return substr(hash('sha256', $canonical), 0, 32);
    }

    public function ensureRoot(): string
    {
        $root = $this->root();
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create the Laravel supervisor runtime root.');
        }
        if (is_link($root)) {
            throw new RuntimeException('The Laravel supervisor runtime root cannot be a symbolic link.');
        }

        return $root;
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
