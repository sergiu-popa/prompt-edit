# Development

## Local development

**Requirements:** Docker and Docker Compose. `prompt-edit.localhost` resolves automatically on macOS; on Linux, add it to `/etc/hosts`.

```bash
make setup   # .env from .env.example, containers, composer + npm, key, migrations, assets
```

`make setup` **overwrites** an existing `.env`. Once your Pixelforge key is in it, use `make up` instead.

The app is served at http://prompt-edit.localhost. `make npm-dev` runs Vite in dev mode.

`make seed` creates the local accounts, and running it again resets them:

| Email | Password | Role |
| --- | --- | --- |
| `demo@promptedit.test` | `promptedit-demo` | user |
| `operator@promptedit.test` | `promptedit-operator` | operator: opens Report |

These credentials are local-only: the seeder refuses to run in production.

Sentry is production only (D-77): `.env.example` leaves `SENTRY_LARAVEL_DSN` empty, so local and E2E never send, and `phpunit.xml` clears it, so no test sends; the tests that check reporting capture events in memory.

Dev and E2E accounts start at $0.0100 (`CREDITS_STARTER_GRANT=10000000`), below the cheapest request's max, so the blocked flow can be seen and tested (D-71). Buy a pack at `/credits`. The seeded demo account also gets one $1.00 demo purchase, so it has $1.0100 on a fresh database.

### Pixelforge (used from PE-009 onwards)

- Local dev and E2E use the **in-app fake** by default (`PIXELFORGE_DRIVER=fake` in `.env.example`). It needs no key and no public URL: the worker moves each generation along on delayed jobs and posts signed webhooks to the in-network app at `http://app:8080/webhooks/pixelforge`. `make setup` fills `PIXELFORGE_WEBHOOK_SECRET` with a random local value for it to sign with. The app refuses to boot with the fake in production.
- To use the real API instead, create **one API key per environment**: local, production. Each key has its own webhook URL, signing secret and `/v1/usage` report, so the deployed app reconciles with its own key. Set `PIXELFORGE_DRIVER=http`, the key as `PIXELFORGE_API_KEY` and its `whsec_…` as `PIXELFORGE_WEBHOOK_SECRET`, never commit them, then `make restart-worker` (the worker reads `.env` once).
- An existing checkout picks up the fake with `PIXELFORGE_DRIVER=fake`, `make webhook-secret` (it keeps a secret already there) and `make restart-worker`.
- Prompt keywords force behaviour for testing: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`. The fake adds `[expire]`, whose image URL dies after a few seconds (`PIXELFORGE_FAKE_EXPIRE_TTL_SECONDS`, default 5).

#### Real webhooks locally

The fake driver (PE-010) stays the default and needs none of this. To receive real Pixelforge webhooks on your machine:

1. Expose the app through a tunnel, for example:
   - Cloudflare Tunnel: `cloudflared tunnel --url http://localhost:80 --http-host-header prompt-edit.localhost`
   - ngrok: `ngrok http 80 --host-header=prompt-edit.localhost`
   - Expose: `expose share http://prompt-edit.localhost`
2. In the Pixelforge dashboard, set your **dev** API key's webhook URL to `https://<tunnel>/webhooks/pixelforge`.
3. Copy that key's `whsec_…` signing secret into `.env` as `PIXELFORGE_WEBHOOK_SECRET`. `.env` is never committed.
4. Press **Send test event** in the dashboard. The `ping` must get a 2xx. `storage/logs/laravel.log` shows why a delivery was refused:
   - `missing`, `malformed`, `mismatch` or `stale` is a signature problem, answered 400. Usually the secret belongs to another key, or the clock is off by more than 5 minutes.
   - A critical "no webhook secret is configured" line, answered 500, means `PIXELFORGE_WEBHOOK_SECRET` is empty.

The endpoint sits outside the session, CSRF and auth middleware, and verifies the HMAC over the exact raw body. Each event id is recorded once, in the same transaction as the change it causes, so duplicates and resends are no-ops.

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
- `make js-test` — Vitest unit + integration projects
- `make e2e` — production build, then Playwright (desktop + mobile) in the `playwright` Docker service; `make e2e filter="name"` to grep
- `make check` — lint + stan + test + js-check + js-test
- `make live-smoke` — opt-in PHPUnit `live` group against the real Pixelforge, with the dev key from `.env` (D-58); never part of `make test`, `make check` or CI
- `make smoke-production` — opt-in Playwright smoke against production (needs `E2E_BASE_URL`, `E2E_REVIEWER_EMAIL`, `E2E_REVIEWER_PASSWORD`); never part of `make e2e` or CI

## Testing

| Layer | Tool | Location | Run |
| --- | --- | --- | --- |
| PHP unit and feature (HTTP, Inertia props, jobs) | PHPUnit against `test-postgres` | `tests/Unit`, `tests/Feature` | `make test` |
| JS unit — pure `resources/js/lib` functions and composables | Vitest (`unit` project, node) | `tests/js/unit` | `make js-test` |
| JS integration — mounted components and pages, Inertia mocked | Vitest + Vue Test Utils (`integration` project, jsdom) | `tests/js/integration` | `make js-test` |
| End-to-end — critical user flows in a real browser | Playwright (`playwright` Compose service) | `tests/e2e` | `make e2e` |
| Live Pixelforge smoke — opt-in, dev key only, never in CI | PHPUnit group `live` | `tests/Feature/Pixelforge/Live` | `make live-smoke` |
| Production smoke — opt-in, never in CI | Playwright (`playwright.production.config.ts`) | `tests/smoke` | `make smoke-production` |

Every Playwright test also fails if the browser logs a console or page error (`tests/e2e/fixtures.ts`).

E2E signs in as the demo account, so run `make seed` once before `make e2e`. Each run also registers a few `e2e-…@promptedit.test` users in the local database, which is harmless.

## Docker Services

Defined in `docker-compose.yml`:

| Service | Container | Purpose |
| --- | --- | --- |
| `traefik` | `prompt-edit-traefik` | Reverse proxy, exposes the app at `prompt-edit.localhost` |
| `app` | `prompt-edit-app` | PHP 8.4 application container (built from `.docker/Dockerfile`) |
| `worker` | `prompt-edit-worker` | Queue worker (`queue:work`) |
| `scheduler` | `prompt-edit-scheduler` | Scheduler (`schedule:work`) |
| `postgres` / `test-postgres` | `prompt-edit-postgres` / `-test-postgres` | Application and tmpfs-backed test databases (PostgreSQL 17) |
| `playwright` | `prompt-edit-playwright` | Playwright 1.65.1 browsers (profile `e2e`; shares the app container's network) |

Xdebug is available by setting `XDEBUG_MODE` in `.env`.

## CI Pipeline

`.github/workflows/ci.yml` runs on every push and is organized into parallel jobs:

1. **Build image** — builds the `ci` target of `.docker/Dockerfile`, pushes/caches it against a branch tag, and shares it with downstream jobs via the GitHub Actions cache.
2. **Code style** — `composer lint` (Pint in `--test` mode).
3. **Frontend checks** — `npm ci`, ESLint, Prettier check, `vue-tsc`, Vitest and a production build on Node 22.
4. **Static analysis** — `composer stan` (PHPStan with result cache restored from the `main` branch).
5. **Tests** — `php artisan migrate` followed by `php artisan test --coverage --min=80` against a Postgres service container. **Builds fail if coverage drops below 80%.**
6. **E2E** — boots the CI image against a Postgres service and runs Playwright inside the app container's network; uploads the report on failure.
7. **Docker push** — publishes the image to GHCR once all checks pass.
8. **Cleanup** — deletes the per-run image cache entry.

Required secrets: `GITHUB_TOKEN` (provided by Actions).

## Project Layout Notes

- `.docker/` — Dockerfile (multi-stage: development and ci; production deploys with Deployer), entrypoint, Apache/PHP/supervisor config.
- `.agent-workflow/` — requirements analysis, story backlog, agent configuration and the prompt log (see [How this is built](process.md)).
- `CLAUDE.md` / `AGENTS.md` — guidelines for AI coding agents; Laravel Boost rules and the Docker command convention.
- `boost.json` — Laravel Boost configuration.
- `phpstan.neon`, `pint.json`, `phpunit.xml` — quality-tool configuration.
