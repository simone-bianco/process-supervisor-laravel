<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Install;

use RuntimeException;

final class EnvironmentFileEditor
{
    /** @param array<string,string> $defaults @return list<string> */
    public function addMissing(string $path, array $defaults): array
    {
        $contents = is_file($path) ? file_get_contents($path) : '';
        if ($contents === false) {
            throw new RuntimeException('Unable to read the environment file.');
        }
        $added = [];
        foreach ($defaults as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
                continue;
            }
            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= PHP_EOL;
            }
            $contents .= $key.'='.$value.PHP_EOL;
            $added[] = $key;
        }
        if ($added === []) {
            return [];
        }
        $this->atomicWrite($path, $contents);

        return $added;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.process-supervisor-env-');
        if (! is_string($temporary) || file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to stage the environment file update.');
        }
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to commit the environment file update.');
        }
    }
}
