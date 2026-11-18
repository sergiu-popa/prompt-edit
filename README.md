# Laravel 13 Starter

Opinionated starter template for Laravel 13 applications, shipped with a Docker-based development environment and a CI pipeline that enforces lint, static analysis, and test coverage.

## Stack

- **PHP** 8.4
- **Laravel** 13
- **Inertia** 3 + **Vue** 3 + **TypeScript** 6
- **Tailwind CSS** 4 + Vite
- **PostgreSQL** 17 and **Redis** (separate containers for app and tests)
- **PHPUnit** 13, **Larastan/PHPStan** 3, **Laravel Pint** 1
- **ESLint**, **Prettier**, **vue-tsc**
- **Laravel Boost** for AI-assisted development

## Requirements

- Docker and Docker Compose
- `prompt-edit.localhost` resolves automatically on macOS; on Linux add an entry to `/etc/hosts`.

## Quick Start

```bash
make setup
```

This target copies `.env.example` to `.env`, boots the containers, installs composer and npm dependencies, generates the app key, runs migrations, and builds frontend assets. The app will be available at http://prompt-edit.localhost.

To run Vite in dev mode instead of a one-shot build:

```bash
make npm-dev
```

## Make Targets

All commands run inside the `prompt-edit-app` container.

### Containers
- `make up` / `make down` / `make restart` — start, stop, restart the stack
- `make bash` — interactive shell in the app container
- `make tinker` — `php artisan tinker`
- `make app-logs` — tail the app container logs

### Database
- `make migrate` / `make fresh` / `make seed` / `make refresh`
- `make migrate-test` / `make fresh-test` / `make refresh-test` — same commands against the isolated test Postgres container

### Frontend
- `make npm` — install npm dependencies
- `make npm-dev` — run Vite dev server
- `make npm-build` — production build
- `make js-check` / `make js-fix` — ESLint, Prettier and vue-tsc

### Quality
- `make lint` / `make lint-fix` — Pint
- `make stan` — PHPStan / Larastan
- `make test` — PHPUnit (optionally `make test filter=testName`)
- `make test-unit` / `make test-feature`
- `make check` — lint + stan + test + js-check

## Docker Services

Defined in `docker-compose.yml`:

| Service | Container | Purpose |
| --- | --- | --- |
| `traefik` | `prompt-edit-traefik` | Reverse proxy, exposes the app at `prompt-edit.localhost` |
| `app` | `prompt-edit-app` | PHP 8.4 application container (built from `.docker/Dockerfile`) |
| `worker` | `prompt-edit-worker` | Queue worker |
| `postgres` / `test-postgres` | `prompt-edit-postgres` / `-test-postgres` | Application and tmpfs-backed test databases (PostgreSQL 17) |
| `redis` / `test-redis` | `prompt-edit-redis` / `-test-redis` | Application and test Redis instances |

Xdebug is available by setting `XDEBUG_MODE` in `.env`.

## CI Pipeline

`.github/workflows/ci.yml` runs on every push and is organized into parallel jobs:

1. **Build image** — builds the `ci` target of `.docker/Dockerfile`, pushes/caches it against a branch tag, and shares it with downstream jobs via the GitHub Actions cache.
2. **Code style** — `composer lint` (Pint in `--test` mode).
3. **Frontend checks** — `npm ci`, ESLint, Prettier check, `vue-tsc` and a production build on Node 22.
4. **Static analysis** — `composer stan` (PHPStan with result cache restored from the `main` branch).
5. **Tests** — `php artisan migrate` followed by `php artisan test --coverage --min=80` against Postgres and Redis service containers. **Builds fail if coverage drops below 80%.**
6. **Docker push** — publishes the image to GHCR once all checks pass.
7. **Cleanup** — deletes the per-run image cache entry.

Required secrets: `GITHUB_TOKEN` (provided by Actions).

## Project Layout Notes

- `.docker/` — Dockerfile (multi-stage: `development`, `ci`, etc.), entrypoint, Apache/PHP/supervisor config.
- `CLAUDE.md` — guidelines for AI coding agents; includes Laravel Boost rules and the Docker command convention.
- `boost.json` — Laravel Boost configuration.
- `phpstan.neon`, `pint.json`, `phpunit.xml` — quality-tool configuration.

## License

MIT.
