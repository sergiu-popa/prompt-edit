# PromptEdit × Pixelforge

Image generation on top of [Pixelforge](https://pixelforge.promptedit.dev/docs), a simulated asynchronous AI image API. It's being built as the take-home assessment for PromptEdit's Senior Product Engineer role. Users sign in, write a prompt, see what it will cost **before** they submit, and watch the generation finish live. They pay Pixelforge's exact cost **plus 25%**, and failed generations are free.

**Stack:** Laravel 13 · Inertia 3 · Vue 3 · TypeScript · Tailwind CSS 4 · PostgreSQL 17 · Docker · GitHub Actions.

## Reviewer guide

- **Live app:** https://prompt.sergiupopa.dev, backed by the real Pixelforge API.
- **Signing in:** the reviewer account is shared with you privately and never appears in this repo. You can also create your own account: each network may create 3 accounts an hour (D-57).
- **Test keywords:** put any of these anywhere in a prompt to force Pixelforge's behaviour:

  | Keyword | What you see |
  | --- | --- |
  | `[succeed]` | Succeeds, never failing at random: the image, the actual cost and the charge |
  | `[fail]` | Accepted, then Failed with the failure copy and "Not charged" |
  | `[blocked]` | Rejected by the content policy at submit, "Not charged" |
  | `[overloaded]` | "Pixelforge is busy — retrying automatically", then Failed after 2 min, "Not charged" |
  | `[slow]` | Processing for 60–90 s, then Succeeded without a reload |
  | `[duplicate]` | Every webhook arrives twice, and the generation is still charged once |

  The reviewer account's History holds a few real generations made with them. The demo set is refreshed shortly before hand-in, because Pixelforge's images expire 24 h after completion.
- **The money model:**
  - amounts are integer nanodollars (1 n$ = $0.000000001), never floats;
  - you pay Pixelforge's exact cost + 25 %, rounded up to the next nanodollar;
  - submitting holds the estimate's maximum, and completion settles the actual cost;
  - a failed or rejected generation is free and its hold is released.

  Details are in [How the money works](docs/how-it-works.md#how-the-money-works).
- **Operator report:** the reviewer account is an operator: Report shows spend, Pixelforge cost and profit per UTC day and per user, exact to the nanodollar on hover. It also reconciles every day with Pixelforge's own `GET /v1/usage`, with a Match badge only when the cost and all four token counts are exactly equal: see [Reading the operator report](docs/how-it-works.md#reading-the-operator-report).
- **Why it is built this way:** every choice is in the [decisions log](.agent-workflow/requirements.md#5-decisions-log), D-01 to D-76.
- **Running the tests:** see [Testing](docs/development.md#testing). `make check` runs PHP lint, static analysis, PHPUnit, the frontend checks and Vitest; `make e2e` runs Playwright against the in-app fake. `make live-smoke` is the one opt-in test against the real API (D-58): it reads a **dev** key from `.env` as `PIXELFORGE_API_KEY`, never the production key, because that would add to production's `/v1/usage` and break its reconciliation (PE-018). It refuses to run in production, in CI and without a key, and costs at most $0.0175 a run.

## Status

| Area | State |
| --- | --- |
| Bootstrap (stack switch, Docker, CI, test harness, worker and scheduler) | Done as stacked PRs #3, #2, #4, #5 and #6, all merged |
| Product features | Delivered as 18 stories (below), each as one reviewable PR |
| Deployment | Live at https://prompt.sergiupopa.dev (Deployer, see [Production](docs/production.md)) since PE-004, on the real Pixelforge API since PE-015 |

### Required scope

| Story | What it delivers |
| --- | --- |
| [PE-001](.agent-workflow/stories/PE-001-money-nanodollars-and-markup/story.md) | Money in integer nanodollars: exact provider cost and the +25% charge |
| [PE-002](.agent-workflow/stories/PE-002-upfront-estimate-shared-fixture/story.md) | Upfront estimate in PHP and TypeScript, locked together by one shared JSON fixture |
| [PE-003](.agent-workflow/stories/PE-003-sign-in-and-app-shell/story.md) | Sign in, sign up and sign out (Fortify + Inertia) with the app shell |
| [PE-004](.agent-workflow/stories/PE-004-walking-skeleton-own-server/story.md) | Walking skeleton live on prompt.sergiupopa.dev |
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

See [docs/how-it-works.md](docs/how-it-works.md#how-the-money-works).

## How it copes with Pixelforge

See [docs/how-it-works.md](docs/how-it-works.md#how-it-copes-with-pixelforge).

## Reading the operator report

See [docs/how-it-works.md](docs/how-it-works.md#reading-the-operator-report).

## Stretch features

See [docs/how-it-works.md](docs/how-it-works.md#stretch-features).

## Local development

See [docs/development.md](docs/development.md#local-development).

## Production

See [docs/production.md](docs/production.md).

## How this is built

See [docs/process.md](docs/process.md).

## Make Targets

See [docs/development.md](docs/development.md#make-targets).

## Testing

See [docs/development.md](docs/development.md#testing).

## Docker Services

See [docs/development.md](docs/development.md#docker-services).

## CI Pipeline

See [docs/development.md](docs/development.md#ci-pipeline).

## Project Layout Notes

See [docs/development.md](docs/development.md#project-layout-notes).

## License

MIT.
