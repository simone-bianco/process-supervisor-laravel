<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimoneBianco\ProcessSupervisorLaravel\Install\ComposerScriptsInstaller;
use SimoneBianco\ProcessSupervisorLaravel\Install\EnvironmentFileEditor;

final class InstallerFilesTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'process-supervisor-installer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporary, 0700, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporary)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporary, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temporary);
        }
        parent::tearDown();
    }

    public function test_environment_editor_adds_only_missing_defaults_and_is_idempotent(): void
    {
        $path = $this->temporary.DIRECTORY_SEPARATOR.'.env';
        file_put_contents($path, "APP_ENV=local\r\nPROCESS_SUPERVISOR_ENABLED=true\r\n");
        $editor = new EnvironmentFileEditor;

        self::assertSame(
            ['PROCESS_SUPERVISOR_REVERB_PORT'],
            $editor->addMissing($path, [
                'PROCESS_SUPERVISOR_ENABLED' => 'false',
                'PROCESS_SUPERVISOR_REVERB_PORT' => '8080',
            ]),
        );
        self::assertSame([], $editor->addMissing($path, [
            'PROCESS_SUPERVISOR_ENABLED' => 'false',
            'PROCESS_SUPERVISOR_REVERB_PORT' => '8080',
        ]));

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('PROCESS_SUPERVISOR_ENABLED=true', $contents);
        self::assertSame(1, substr_count($contents, 'PROCESS_SUPERVISOR_ENABLED='));
        self::assertSame(1, substr_count($contents, 'PROCESS_SUPERVISOR_REVERB_PORT='));
    }

    public function test_composer_scripts_installer_preserves_existing_commands_and_is_idempotent(): void
    {
        $path = $this->temporary.DIRECTORY_SEPARATOR.'composer.json';
        file_put_contents($path, json_encode([
            'name' => 'example/app',
            'scripts' => [
                'post-install-cmd' => ['@php artisan existing:install'],
                'post-update-cmd' => ['@php artisan existing:update'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $installer = new ComposerScriptsInstaller;

        self::assertSame(['post-install-cmd', 'post-update-cmd'], $installer->install($path));
        self::assertSame([], $installer->install($path));

        $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $hook = '@php artisan process-supervisor:sync-python --no-interaction';
        self::assertSame(['@php artisan existing:install', $hook], $composer['scripts']['post-install-cmd']);
        self::assertSame(['@php artisan existing:update', $hook], $composer['scripts']['post-update-cmd']);
    }
}
