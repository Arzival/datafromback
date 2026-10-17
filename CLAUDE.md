# datafront · backend — notas para agentes

Esto es el backend (Laravel) de **datafront**, una bóveda local de capturas de UI que clasifica la IA. El frontend vive en el repo hermano `datafront`.

- **Para instalar o dejar lista la app**, sigue la sección **«Instrucciones para agentes de IA»** del `README.md`. Ahí están los pasos, cómo verificar cada uno y cuándo la tarea está terminada.
- **Nunca borres** `storage/app/vault/` ni `database/database.sqlite`: son los datos del usuario y no hay otra copia. Nada de `migrate:fresh`, `migrate:reset` ni `db:wipe` sobre una base con datos.
- **Sin el worker de la cola no se clasifica nada** (`./scripts/dev.sh` o `php artisan queue:work --tries=2 --timeout=300`).
- **La API no tiene autenticación.** No la expongas fuera de `127.0.0.1`.
- **El inicio de sesión en `claude` o `codex` lo hace el usuario.** Tú no.

<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>
