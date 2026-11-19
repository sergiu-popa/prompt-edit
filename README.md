# PromptEdit × Pixelforge

Image generation on top of [Pixelforge](https://pixelforge.promptedit.dev/docs), a simulated asynchronous AI image API. It's being built as the take-home assessment for PromptEdit's Senior Product Engineer role. Users sign in, write a prompt, see what it will cost **before** they submit, and watch the generation finish live. They pay Pixelforge's exact cost **plus 25%**, and failed generations are free.

**Stack:** Laravel 13 · Inertia 3 · Vue 3 · TypeScript · Tailwind CSS 4 · PostgreSQL 17 · Redis · Docker · GitHub Actions.

## Status

| Area | State |
| --- | --- |
| Bootstrap (stack switch, Docker, CI, test harness, worker and scheduler) | Done as stacked PRs, to merge in order: #3 → #2 → #4 → #5 → #6 |
| Product features | Planned as 18 stories (below); each is delivered as one reviewable PR |
| Deployment | Laravel Cloud: walking skeleton in PE-004, real Pixelforge in PE-015 |

### Required scope

| Story | What it delivers |
| --- | --- |
| [PE-001](.agent-workflow/stories/PE-001-money-nanodollars-and-markup/story.md) | Money in integer nanodollars: exact provider cost and the +25% charge |
| [PE-002](.agent-workflow/stories/PE-002-upfront-estimate-shared-fixture/story.md) | Upfront estimate in PHP and TypeScript, locked together by one shared JSON fixture |
| [PE-003](.agent-workflow/stories/PE-003-sign-in-and-app-shell/story.md) | Sign in, sign up and sign out (Fortify + Inertia) with the app shell |
| [PE-004](.agent-workflow/stories/PE-004-walking-skeleton-laravel-cloud/story.md) | Walking skeleton live on Laravel Cloud |
| [PE-005](.agent-workflow/stories/PE-005-generate-page-live-estimate/story.md) | Generate page with an instant upfront cost estimate |
| [PE-006](.agent-workflow/stories/PE-006-generation-record-and-transitions/story.md) | Generation record and the single forward-only transition action |
| [PE-007](.agent-workflow/stories/PE-007-credit-ledger-hold-settle-release/story.md) | Append-only credit ledger: hold the max estimate, charge the actual exactly once, failed is free |
| [PE-008](.agent-workflow/stories/PE-008-verified-idempotent-webhooks/story.md) | Signature-verified, idempotent, order-independent Pixelforge webhooks |
| [PE-009](.agent-workflow/stories/PE-009-pixelforge-client-and-submit-job/story.md) | Pixelforge client contract and the idempotent submit job |
| [PE-010](.agent-workflow/stories/PE-010-fake-pixelforge-driver/story.md) | In-app fake Pixelforge with signed webhooks and test keywords |
| [PE-011](.agent-workflow/stories/PE-011-submit-generation-and-history/story.md) | Submit a generation and see it in history |
| [PE-012](.agent-workflow/stories/PE-012-live-status-results-and-failure-copy/story.md) | Live status, image and actual cost in history; failures explained |
| [PE-013](.agent-workflow/stories/PE-013-provider-limits-retries-and-throttle/story.md) | Provider limits, overloads and outages: bounded retries, free failures and a shared rate budget |
| [PE-014](.agent-workflow/stories/PE-014-reconciliation-poller/story.md) | Scheduled reconciler: status and money catch up when webhooks don't arrive |
| [PE-015](.agent-workflow/stories/PE-015-production-go-live/story.md) | Go live with real Pixelforge: live smoke, production webhooks, hardening and first real generations |

### Stretch goals

| Story | What it delivers |
| --- | --- |
| [PE-016](.agent-workflow/stories/PE-016-operator-report/story.md) | Operator report: credit spend, provider cost and profit per day and per user |
| [PE-017](.agent-workflow/stories/PE-017-credit-balances-buy-credits/story.md) | Credit balances, fake 'Buy credits', and blocking unaffordable generations |
| [PE-018](.agent-workflow/stories/PE-018-usage-reconciliation-and-final-signoff/story.md) | Provider costs reconcile with GET /v1/usage; final production sign-off |

## How the money works

- **Integer nanodollars** (1 n$ = $0.000000001), stored as `BIGINT`, never floats. Pixelforge's per-1M-token rates are whole n$ per token (`"12.50"` → 12,500 n$/token). Provider cost is therefore exact and matches the 9-decimal `cost_usd` in `GET /v1/usage` digit for digit.
- **Billed at the generation's own rates.** Each generation is priced with the `rates` Pixelforge returns on it, never with a hard-coded table.
- **User price = ⌈provider × 125 / 100⌉.** It rounds up to the next nanodollar, so it never under-charges and over-charges by at most 0.75 n$.
- **The upfront estimate is an honest range.** Text tokens are ±15% of ⌈code points / 4⌉ and reasoning tokens vary by quality. One shared JSON fixture keeps the PHP and TypeScript estimators identical.
- **Exactly once, enforced by the database.** An append-only ledger holds the maximum at submit, charges the actual once on success (a unique constraint, not a convention) and releases the hold on failure.
- **Reconciles with Pixelforge.** The operator report buckets costs by Pixelforge's `completed_at` UTC day, the same way `/v1/usage` does.

Worked examples and the full model are in [`requirements.md` §3](.agent-workflow/requirements.md#3-money-model).

## How it copes with Pixelforge

- **Local row first.** Its ULID is both the `Idempotency-Key` and `metadata.generation_id`. Retries are safe, and a webhook that arrives before the create response still finds its row.
- **Webhooks are verified, deduplicated and order-independent.** The HMAC-SHA256 signature over `"<t>.<raw body>"` is checked with a 5-minute tolerance. The event id is deduplicated in the same transaction as the state change, and the state machine only moves forward: a late `processing` can never undo `succeeded`.
- **Limits and overloads.** `429` (rate limit or concurrency) and `503` are retried, honouring `Retry-After` and reusing the same idempotency key. Definitive rejections (content policy, validation) fail free with a clear message, and so does the daily limit.
- **Lost webhooks.** A scheduled reconciler polls stuck generations through the same transition path.

Every failure mode is covered in the [robustness matrix](.agent-workflow/requirements.md#4-robustness-matrix). The reasoning behind each choice is in the [decisions log](.agent-workflow/requirements.md#5-decisions-log).

## Local development

**Requirements:** Docker and Docker Compose. `prompt-edit.localhost` resolves automatically on macOS; on Linux, add it to `/etc/hosts`.

```bash
make setup   # .env from .env.example, containers, composer + npm, key, migrations, assets
```

`make setup` **overwrites** an existing `.env`. Once your Pixelforge key is in it, use `make up` instead.

The app is served at http://prompt-edit.localhost. `make npm-dev` runs Vite in dev mode.

### Pixelforge (used from PE-009 onwards)

- Create **one API key per environment**: local, production. Each key has its own webhook URL, signing secret and `/v1/usage` report, so the deployed app reconciles with its own key.
- Put the key and its webhook signing secret in `.env` as `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`, and never commit them. `PIXELFORGE_DRIVER` switches between the real API and the in-app fake (PE-010), which also drives E2E.
- To receive real webhooks locally, expose the app through a tunnel and set `https://<tunnel>/webhooks/pixelforge` as the key's webhook URL in the Pixelforge dashboard. For example:
  - `cloudflared tunnel --url http://localhost:80 --http-host-header prompt-edit.localhost`
  - `ngrok http 80 --host-header=prompt-edit.localhost`
- Prompt keywords force behaviour for testing: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`.

## How this is built

- **Requirements first.** [`requirements.md`](.agent-workflow/requirements.md) distils the brief and the Pixelforge docs, checked against the live API, into facts, a money model, a robustness matrix and a decisions log.
- **Stories, then an agent pipeline.** Each [story](.agent-workflow/stories) moves through *Architect* (`plan.md`) → *Engineer* (code and tests) → *Reviewer* (`review.md`) → *QA* (`qa.md`, including a browser pass), with an optional *Auditor* for risky stories. The whole decision trail is committed next to the code. Project rules for the agents are in [`.agent-workflow/CLAUDE.md`](.agent-workflow/CLAUDE.md).
- **Bootstrap work has no stories.** Tooling such as the stack switch, CI and the test harness goes on `bootstrap/*` branches with `[bootstrap]` commits.
- **Git hygiene.** One story = one PR of about 400 changed lines, short `[PE-NNN]` commits that each carry their own tests, and TDD throughout.
- **AI tools.** Built with Claude Code. Every prompt is logged verbatim in [`.agent-workflow/prompts.md`](.agent-workflow/prompts.md).

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

## Testing

| Layer | Tool | Location | Run |
| --- | --- | --- | --- |
| PHP unit and feature (HTTP, Inertia props, jobs) | PHPUnit against `test-postgres` | `tests/Unit`, `tests/Feature` | `make test` |
| JS unit — pure `resources/js/lib` functions and composables | Vitest (`unit` project, node) | `tests/js/unit` | `make js-test` |
| JS integration — mounted components and pages, Inertia mocked | Vitest + Vue Test Utils (`integration` project, jsdom) | `tests/js/integration` | `make js-test` |
| End-to-end — critical user flows in a real browser | Playwright (`playwright` Compose service) | `tests/e2e` | `make e2e` |

Every Playwright test also fails if the browser logs a console or page error (`tests/e2e/fixtures.ts`).

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
| `redis` / `test-redis` | `prompt-edit-redis` / `-test-redis` | Application and test Redis instances |

Xdebug is available by setting `XDEBUG_MODE` in `.env`.

## CI Pipeline

`.github/workflows/ci.yml` runs on every push and is organized into parallel jobs:

1. **Build image** — builds the `ci` target of `.docker/Dockerfile`, pushes/caches it against a branch tag, and shares it with downstream jobs via the GitHub Actions cache.
2. **Code style** — `composer lint` (Pint in `--test` mode).
3. **Frontend checks** — `npm ci`, ESLint, Prettier check, `vue-tsc`, Vitest and a production build on Node 22.
4. **Static analysis** — `composer stan` (PHPStan with result cache restored from the `main` branch).
5. **Tests** — `php artisan migrate` followed by `php artisan test --coverage --min=80` against Postgres and Redis service containers. **Builds fail if coverage drops below 80%.**
6. **E2E** — boots the CI image against Postgres + Redis services and runs Playwright inside the app container's network; uploads the report on failure.
7. **Docker push** — publishes the image to GHCR once all checks pass.
8. **Cleanup** — deletes the per-run image cache entry.

Required secrets: `GITHUB_TOKEN` (provided by Actions).

## Project Layout Notes

- `.docker/` — Dockerfile (multi-stage: `development`, `ci`, etc.), entrypoint, Apache/PHP/supervisor config.
- `.agent-workflow/` — requirements analysis, story backlog, agent configuration and the prompt log (see *How this is built*).
- `CLAUDE.md` / `AGENTS.md` — guidelines for AI coding agents; Laravel Boost rules and the Docker command convention.
- `boost.json` — Laravel Boost configuration.
- `phpstan.neon`, `pint.json`, `phpunit.xml` — quality-tool configuration.

## License

MIT.
