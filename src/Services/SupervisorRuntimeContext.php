<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use InvalidArgumentException;

final readonly class SupervisorRuntimeContext
{
    /**
     * @param  array<string,mixed>  $queue
     * @param  array<string,string>  $childEnvironment
     */
    public function __construct(
        public string $projectRoot,
        public string $runtimeRoot,
        public string $phpExecutable,
        public array $queue,
        public bool $schedulerEnabled,
        public bool $reverbEnabled,
        public bool $queueEnabled = true,
        public string $timezone = 'UTC',
        public array $childEnvironment = [],
        public ?int $reverbPort = null,
    ) {
        foreach (['projectRoot' => $projectRoot, 'runtimeRoot' => $runtimeRoot, 'phpExecutable' => $phpExecutable] as $name => $path) {
            if (! self::absolute($path)) {
                throw new InvalidArgumentException("Supervisor runtime context {$name} must be an absolute path.");
            }
        }
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
