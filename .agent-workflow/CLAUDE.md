# CLAUDE.md — Project Agent Configuration

@~/.claude/docs/laravel-beyond-crud-guidelines.md

## Project
- **Name:** PromptEdit — Pixelforge image generation (take-home assessment)
- **Stack:** Laravel 13, PHP 8.4, Inertia + Vue 3 + TypeScript, Tailwind CSS 4, PostgreSQL 17, Redis, Laravel Fortify (auth)
- **Requirements + Pixelforge facts + decisions log:** `.agent-workflow/requirements.md` — read it before planning any story.

## Stories
Stories live in `.agent-workflow/stories/<STORY_ID>/` and are committed to this repo.

## Story ID prefix
Use `PE` (e.g. `PE-001-authentication`). Stories are for product functionality only — bootstrap/scaffolding/tooling changes (stack switch, renames, CI, test harness) are done directly on `bootstrap/*` branches with `[bootstrap]` commit prefixes and no story folder.

## Domain map

| Domain | Purpose |
|---|---|
| `Generation` | Generation lifecycle: creation, submission, forward-only state machine, history |
| `Billing` | Money (integer nanodollars), pricing, estimates, markup, credit ledger, balances |
| `Reporting` | Operator report: spend, provider cost, profit, `/v1/usage` reconciliation |
| `Shared` | Cross-domain value objects and enums |

Provider integration lives in `app/Support/Pixelforge/` (HTTP client, response
DTOs, typed exceptions, webhook signature verification, fake driver). Domains
depend on its contract, never on `Http::` directly.

## Project rules

### Overrides to the global guidelines
**§5 (Livewire 4) does not apply — this project uses Inertia + Vue.** Replace it with:

- **Controllers are thin.** Validate with a Form Request, authorize with a policy/gate, call one Action, then `Inertia::render()` or redirect. Name parity applies to `…Action` / `…Controller` / `…Request`.
- **Props are explicit.** Shape page props with API Resources or DTO `toArray()` — never pass Eloquent models raw. Each prop has a matching TS type in `resources/js/types/`.
- **Money crosses the wire as integer nanodollars.** Only `resources/js/lib/money.ts` formats it. No floats for money anywhere, in PHP or TS.
- **Vue layout:**
  - `resources/js/pages/<Area>/<Name>.vue` — Inertia pages: thin, wire props to components.
  - `resources/js/components/ui/` — presentational primitives (Button, Input, Badge…): props in, events out, no Inertia imports. Visual language inspired by Flux UI; never copy Flux source (licensed).
  - `resources/js/components/<feature>/` — feature components.
  - `resources/js/composables/useX.ts` — stateful logic.
  - `resources/js/lib/*.ts` — pure functions, no Vue or Inertia imports.
- `<script setup lang="ts">` only. No `any`. `vue-tsc --noEmit` must pass.

### Testing layers (TDD: write the failing test first)
| Layer | Tool | Location |
|---|---|---|
| PHP unit — Actions, value objects, calculators | PHPUnit | `tests/Unit` |
| PHP feature — HTTP, Inertia props (`assertInertia`), jobs, webhooks (`Http::fake`) | PHPUnit | `tests/Feature` |
| JS unit — `lib/`, composables | Vitest | `tests/js/unit` |
| JS integration — components and pages, Inertia mocked | Vitest + Vue Test Utils | `tests/js/integration` |
| E2E — critical user flows against the running app (fake Pixelforge driver) | Playwright | `tests/e2e` |

Money logic that exists in both PHP and TS is tested against the **same JSON fixture file** from both suites.

### Pixelforge rules (see requirements.md §2–4)
- Idempotency key = the generation's ULID, also sent as `metadata.generation_id`. Never an auto-increment id.
- One forward-only transition action handles webhooks, create responses and polling. Terminal states never change.
- Every money write is exactly-once by a DB unique constraint, not just by a status check.
- Never call the real Pixelforge API from tests. Use `Http::fake()` or the fake driver.
- Never print, log or commit `PIXELFORGE_API_KEY` / `PIXELFORGE_WEBHOOK_SECRET`.

### Git hygiene
- One branch and one PR per story; PRs stay small enough to review in one sitting (≈ 400 changed lines excluding lockfiles and generated files — split the story if larger).
- Short commits, one logical task each: `[PE-NNN] Short description`. Each commit has its test and passes the suite.
- Stage explicit paths. Never `git add -A`.

### Prompt log
Append every user prompt, verbatim, to `.agent-workflow/prompts.md` with the date and story/context. Long pasted documents may be summarised with a pointer to where they are captured.

## Commands
```bash
make test                 # PHPUnit (make test filter=Name)
make lint / make lint-fix # Pint
make stan                 # Larastan
make check                # lint + stan + test
make js-test              # Vitest (unit + integration)
make js-check             # ESLint + vue-tsc
make e2e                  # Playwright (Docker service)
```
