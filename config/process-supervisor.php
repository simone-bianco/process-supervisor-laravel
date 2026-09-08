<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PROCESS_SUPERVISOR_ENABLED', false),
    'runtime_root' => env('PROCESS_SUPERVISOR_RUNTIME_ROOT', storage_path('framework/process-supervisor/runtime')),
    'php_binary' => env('PROCESS_SUPERVISOR_PHP_BINARY'),
    'python_module' => env('PROCESS_SUPERVISOR_PYTHON_MODULE', 'py_laravel_supervisor.cli'),
    'python' => [
        'venv' => env('PROCESS_SUPERVISOR_PYTHON_VENV', storage_path('framework/process-supervisor/python-venv')),
        'executable' => env('PROCESS_SUPERVISOR_PYTHON_BINARY'),
        'uv_binary' => env('PROCESS_SUPERVISOR_UV_BINARY', 'uv'),
        'package' => env(
            'PROCESS_SUPERVISOR_PYTHON_PACKAGE',
            is_dir(base_path('packages/simone-bianco/process-supervisor-python'))
                ? base_path('packages/simone-bianco/process-supervisor-python')
                : 'git+https://github.com/simone-bianco/process-supervisor-python.git@main',
        ),
    ],
    'control_timeout_seconds' => 8,
    'status_timeout_seconds' => 5,
    'heartbeat_stale_seconds' => 10,
    'startup_grace_seconds' => 10,
    'shutdown_timeout_seconds' => 12,
    'max_processes' => 8,
    'control_lock_timeout_ms' => 5_000,
    'queue' => [
        'id' => 'queue-default',
        'label' => 'Laravel queue',
        'connection' => env('QUEUE_CONNECTION', config('queue.default')),
        'queues' => ['default'],
        'processes' => 1,
        'tries' => 1,
        'backoff' => [0],
        'sleep_seconds' => 1,
        'watchdog_seconds' => 120,
        'stop_grace_seconds' => 10,
        'restart_policy' => [
            'enabled' => true,
            'base_delay_seconds' => 0.25,
            'max_delay_seconds' => 10,
            'crash_window_seconds' => 60,
            'max_crashes' => 5,
        ],
    ],
    'scheduler' => [
        'id' => 'scheduler',
        'label' => 'Laravel scheduler',
        'cron' => '* * * * *',
        'watchdog_seconds' => 90,
        'stop_grace_seconds' => 65,
        'restart_policy' => [
            'enabled' => true,
            'base_delay_seconds' => 0.25,
            'max_delay_seconds' => 10,
            'crash_window_seconds' => 60,
            'max_crashes' => 5,
        ],
    ],
    'reverb' => [
        'id' => 'reverb',
        'port' => (int) env('PROCESS_SUPERVISOR_REVERB_PORT', 8080),
        'label' => 'Reverb',
        'stop_grace_seconds' => 10,
        'restart_policy' => [
            'enabled' => true,
            'base_delay_seconds' => 0.25,
            'max_delay_seconds' => 10,
            'crash_window_seconds' => 60,
            'max_crashes' => 5,
        ],
    ],
    // Trusted server configuration only: ID => label, command (no arguments), optional listen host/port.
    'services' => [],
    'vue_package' => env('PROCESS_SUPERVISOR_VUE_PACKAGE', 'github:simone-bianco/process-supervisor-vue'),
];
