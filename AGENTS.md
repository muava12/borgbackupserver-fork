# BorgBackupServer Fork — AGENTS.md

## Project Overview

**Fork** of [marcpope/borgbackupserver](https://github.com/marcpope/borgbackupserver) — centralized Borg backup management for Linux endpoints. This fork adds **ARM64 support** and **SQLite catalog fallback** for environments where ClickHouse is infeasible.

- **Language:** PHP 8.4, Python 3 (agent)
- **Architecture:** Custom MVC (no framework), PSR-4 autoload
- **Database:** MariaDB (bundled in Docker), ClickHouse 26.5.2.39 (catalog engine, amd64 only), SQLite 3 (ARM64 fallback)
- **Container:** Docker with borgbackup, rclone, MariaDB
- **CI:** GitHub Actions (AMD64 + ARM64 native)

## Git Remotes

| Remote     | URL                                                |
| ---------- | -------------------------------------------------- |
| `origin`   | `https://github.com/muava12/borgbackupserver-fork` |
| `upstream` | `https://github.com/marcpope/borgbackupserver.git` |

## Quick Start

```bash
# Install PHP deps
composer install

# Copy env
cp .env.example .env

# Start with Docker
docker compose up -d

# Or dev server (PHP built-in)
php -S 0.0.0.0:8080 -t public/
```

## Development Workflow

- **PHP built-in server:** `php -S 0.0.0.0:8080 -t public/`
- **Docker:** `docker compose up -d` (ARM64 skips ClickHouse)
- **Config:** `.env` file at project root
- **Logs:** `docker compose logs -f app`

## Testing

```bash
# Run all tests
./vendor/bin/phpunit

# Run single test class
./vendor/bin/phpunit tests/SomeTest.php

# PHP syntax lint
find src/ -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors detected'
```

## Code Style

- **PSR-4 autoload** under `BBS\` namespace, mapped to `src/`
- **PSR-12** coding style
- **Naming:** PascalCase classes, camelCase methods, snake_case DB columns
- **Routing:** AltoRouter in `public/index.php`
- **Views:** Plain PHP templates in `src/Views/`
- **Agent:** Python 3 in `agent/bbs-agent.py`

## Build & Deployment

### Docker

```bash
# AMD64 (with ClickHouse)
docker compose build

# ARM64 (skip ClickHouse)
docker buildx build --platform linux/arm64 -t bbs-fork:latest .

# Multi-arch
docker buildx build --platform linux/amd64,linux/arm64 -t ghcr.io/muava12/borgbackupserver-fork:latest --push .
```

### CI/CD

- **AMD64:** `.github/workflows/docker-publish.yml` (upstream workflow, Docker Hub)
- **ARM64:** `.github/workflows/docker-build-arm64.yml` (fork, native ARM64 runner, ghcr.io)
- ARM64 build triggers on: push to `main`, release published, workflow_dispatch
- Notifications: ntfy.sh on ARM64 build status

## Architecture Rules (Fork-Specific)

1. **Never hardcode ClickHouse availability** — always gate with try-catch → SQLite fallback
2. **Never assume ClickHouse exists** in Dockerfile — ARM64 builds skip CH
3. **When adding new catalog features**, duplicate the path: ClickHouse native + SQLite fallback
4. **Test pinned CH 26.5.2.39** on ARM64 first — if it works, SQLite fallback may be unnecessary

## Key Files

### Fork-Specific (new files)

| File | Purpose |
|------|---------|
| `src/Core/SQLiteCatalog.php` | PDO SQLite singleton, WAL mode, auto-schema |
| `.github/workflows/docker-build-arm64.yml` | ARM64 native CI |
| `FORK_ARCHITECTURE.md` | Canonical fork divergence doc |

### Heavily Patched

| File | Fork change |
|------|-------------|
| `src/Core/ClickHouse.php` | try-catch wrappers with SQLite fallback |
| `src/Services/ServerStats.php` | CH → SQLite routing |
| `src/Services/CatalogImporter.php` | Idempotent SQLite import |
| `src/Services/S3SyncService.php` | `$catalogDb` abstraction |
| `src/Controllers/Api/*ApiController.php` | SQLite catalog endpoints |
| `src/Controllers/DashboardController.php` | SQLite pie chart |
| `entrypoint.sh` | CH skip + SQLite init |
| `Dockerfile` | ARM64 CH gate |
| `docker-compose.yml` | No CH healthcheck |
| `agent/bbs-agent.py` | OS sleep prevention, path spaces, SIGTERM on shutdown |

## Pull Request Guidelines

- **Title:** `type(scope): brief description` (conventional commits)
  - Types: `feat`, `fix`, `ci`, `docs`, `chore`
  - Scopes: `arm64`, `catalog`, `agent`, `docker`, `ui`
- **Pre-commit:** `php -l` on changed PHP files, Python syntax check on agent
- **Branch:** feature branches from `main`, PR to `main`
- **Upstream sync:** use `sync-upstream-v*` branches from `main`
