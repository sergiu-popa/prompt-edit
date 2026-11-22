# PromptEdit × Pixelforge

Image generation on top of [Pixelforge](https://pixelforge.promptedit.dev/docs), a simulated asynchronous AI image API. It's being built as the take-home assessment for PromptEdit's Senior Product Engineer role. Users sign in, write a prompt, see what it will cost **before** they submit, and watch the generation finish live. They pay Pixelforge's exact cost **plus 25%**, and failed generations are free.

**Stack:** Laravel 13 · Inertia 3 · Vue 3 · TypeScript · Tailwind CSS 4 · PostgreSQL 17 · Docker · GitHub Actions.

**Live app:** https://prompt.sergiupopa.dev, backed by the real Pixelforge API.

## Reviewer quick start

- **Signing in:** the reviewer account is shared with you privately and never appears in this repo. You can also create your own account.
- **Test keywords:** put `[succeed]`, `[fail]`, `[blocked]`, `[overloaded]`, `[slow]` or `[duplicate]` anywhere in a prompt to force Pixelforge's behaviour.
- **Operator report:** the reviewer account is an operator, so Report shows spend, Pixelforge cost and profit per UTC day and per user.
- **Running the tests:** `make check` runs PHP lint, static analysis, PHPUnit, the frontend checks and Vitest; `make e2e` runs Playwright against the in-app fake.

What each keyword shows, the money model in brief and the status of all 18 stories are in the [reviewer guide](docs/reviewer-guide.md).

## Key design points

- **Integer nanodollars.** Amounts are integer nanodollars (1 n$ = $0.000000001), stored as `BIGINT`, never floats. You pay Pixelforge's exact cost + 25%, rounded up to the next nanodollar.
- **Hold the maximum, charge the actual exactly once.** An append-only ledger holds the estimate's maximum at submit, charges the actual once on success (a unique constraint, not a convention) and releases the hold on failure, so a failed or rejected generation is free.
- **Verified, idempotent webhooks, plus a reconciler.** Every webhook's HMAC-SHA256 signature is checked, its event id is deduplicated in the same transaction as the state change, and the state machine only moves forward. A scheduled reconciler polls the generations Pixelforge has sent no news about, through the same transition path, so a poll and a webhook settle exactly once in either order.
- **One shared rate budget.** Every call takes from one shared budget of 50 requests a minute, below Pixelforge's 60. Rate limits and overloads are retried after `Retry-After` with the same idempotency key; a slot that never frees up or an overload that lasts fails free.
- **An operator report reconciled with `/v1/usage`.** Spend, Pixelforge cost and profit per UTC day and per user. Every day is checked against Pixelforge's own `GET /v1/usage`, and it is a Match only when the cost and all four token counts are exactly equal.
- **Balances and Buy credits.** The balance is the ledger: there is no balance column that could drift. An unaffordable generation is refused up front, and Buy credits is fake: it adds a pack and takes no payment.

## Local development

**Requirements:** Docker and Docker Compose. `prompt-edit.localhost` resolves automatically on macOS; on Linux, add it to `/etc/hosts`.

```bash
make setup   # .env from .env.example, containers, composer + npm, key, migrations, assets
make seed    # the local demo and operator accounts
```

`make setup` **overwrites** an existing `.env`. Once your Pixelforge key is in it, use `make up` instead.

The app is served at http://prompt-edit.localhost. It uses the in-app fake Pixelforge by default, which needs no key and no public URL. The local accounts, the real API and the make targets are in [Development](docs/development.md).

## Documentation

| Document | What it covers |
| --- | --- |
| [Reviewer guide](docs/reviewer-guide.md) | Signing in, the test keywords, the money model in brief, running the tests and the status of every story |
| [How it works](docs/how-it-works.md) | The money model, balances and Buy credits, how the app copes with Pixelforge, and reading the operator report |
| [Development](docs/development.md) | Local setup, Pixelforge locally (fake or real, webhooks through a tunnel), make targets, testing, Docker services, CI and the project layout |
| [Production](docs/production.md) | The droplet, provisioning, the reviewer and operator accounts, deploys, Pixelforge on production, go-live checks, the demo refresh, logs and backups |
| [How this is built](docs/process.md) | Requirements first, stories through an agent pipeline, git hygiene and the AI tools |
| [Requirements](.agent-workflow/requirements.md) | The brief and the Pixelforge facts, the money model, the robustness matrix and the decisions log (D-01 to D-76) |
| [Stories](.agent-workflow/stories) | One folder per story: the story, its plan, review, QA and audit |

## License

MIT.
