# Installation and host integration

## Installer flow

`process-supervisor:install` is intentionally interactive. It explains what each action changes before asking for confirmation.

```mermaid
flowchart TD
    Start[process-supervisor:install] --> Config{Publish config?}
    Config --> Env{Add missing env defaults?}
    Env --> Python{Install/sync Python engine?}
    Python --> Hooks{Add Composer sync hooks?}
    Hooks --> Vue{Install optional Vue package?}
    Vue --> Done[Installation complete]
```

No application file is modified silently by the installer.

## Python requirements

The Python engine installer expects:

- Python supported by `uv`
- `uv` available on PATH or configured through `PROCESS_SUPERVISOR_UV_BINARY`
- a Windows host for the current v1 runtime

The venv path can be changed with `PROCESS_SUPERVISOR_PYTHON_VENV`.

## Recommended environment defaults

The installer can add these only when they are missing:

```env
PROCESS_SUPERVISOR_ENABLED=false
PROCESS_SUPERVISOR_REVERB_PORT=8080
```

Existing values are preserved.

## Composer synchronization

If accepted, the installer adds this command to root `post-install-cmd` and `post-update-cmd`:

```text
@php artisan process-supervisor:sync-python --no-interaction
```

This keeps the Python engine synchronized with Composer deployments without turning Python into a fake Composer dependency.

## Custom host authority

A host application can bind its own implementations for availability, timezone, Reverb port and diagnostics.

```mermaid
flowchart LR
    Settings[Application Settings] --> Availability[AvailabilityProvider]
    Settings --> Timezone[TimezoneProvider]
    Settings --> Port[ReverbPortProvider]
    Diagnostics[Application diagnostics] --> Sink[DiagnosticsSink]
    Availability --> Package[Laravel package]
    Timezone --> Package
    Port --> Package
    Package --> Sink
```

This is how Local GPT uses database-backed Settings while the standalone package can still operate with config defaults.