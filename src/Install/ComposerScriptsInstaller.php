<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Install;

use JsonException;
use RuntimeException;

final class ComposerScriptsInstaller
{
    private const string HOOK = '@php artisan process-supervisor:sync-python --no-interaction';

    /** @return list<string> */
    public function install(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read composer.json.');
        }
        try {
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('composer.json is invalid JSON.', previous: $exception);
        }
        if (! is_array($composer)) {
            throw new RuntimeException('composer.json must contain an object.');
        }
        $composer['scripts'] = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        $changed = [];
        foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
            $commands = $composer['scripts'][$event] ?? [];
            $commands = is_array($commands) ? array_values($commands) : [$commands];
            if (! in_array(self::HOOK, $commands, true)) {
                $commands[] = self::HOOK;
                $composer['scripts'][$event] = $commands;
                $changed[] = $event;
            }
        }
        if ($changed === []) {
            return [];
        }
        try {
            $encoded = json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode composer.json.', previous: $exception);
        }
        $temporary = tempnam(dirname($path), '.process-supervisor-composer-');
        if (! is_string($temporary) || file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to stage composer.json update.');
        }
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to commit composer.json update.');
        }

        return $changed;
    }
}
