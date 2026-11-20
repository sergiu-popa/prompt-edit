# Audit — PE-012-live-status-results-and-failure-copy

**Verdict: PASS**

Run on branch `pe-012-live-status-results-and-failure-copy` from `636fc26` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (round 2 APPROVE) and `qa.md` (QA PASSED) present.

## Scope

I read the whole `[PE-012]` diff (`main...636fc26`, 34 files) against `story.md` AC 1–18, `plan.md`, both review rounds, QA and D-41 to D-43. That covers:
- `GenerationResource`, `GenerationFailureCode`, `LocalFailureReason`'s docblock and `ShowGenerationHistoryController`;
- `useLiveUpdates`, `useServerClock`, `lib/generationTiming.ts`, `lib/relativeTime.ts`, `HistoryItem`, `ResultImage`, `LiveUpdatesNote`, History's page and the TS types;
- every new or changed PHPUnit, Vitest and Playwright test.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene).

## What was verified

- **Polling (AC 1–4, D-41).**
  - It only ever reaches our own server. `router.reload({only: ['generations']})` hits `generations.index`, which reads the database alone. `GenerationHistoryLiveUpdatesTest` reloads with no `Http::fake()`, so the global `preventStrayRequests()` would throw on any outbound call, and it asserts nothing was queued.
  - The partial reload carries only `generations`, which holds `server_time`. The shared `auth.user` is a closure, so a reload doesn't resolve it (`->missing('auth')`).
  - It stops when nothing shown is in progress, because the `active` watcher clears the timer. Dispose clears the timer, cancels a poll in flight through its token, and `isDisposed` stops a late `onFinish` from rescheduling. No timer is left behind. The clock's 1 s interval is cleared on dispose too.
  - The backoff is `[6, 12, 24, 30] s`, indexed by failures in a row and capped at 30 s. The first success resets it to 3 s and clears the note. The next poll is scheduled from the previous one's finish, so polls never overlap.
- **Load.** One open tab with something in progress makes one GET every 3 s or more, never concurrently. Each GET is the session lookup and write, plus two indexed queries (the page and its count). `EXPLAIN` on the dev database shows `Index Scan Backward using generations_user_id_created_at_id_index` with `Index Cond: (user_id = …)` and no sort node, so the PE-011 index serves the list. The resource reads no relation, and PE-011's query-count row still holds, so there is no N+1. A tab whose items are all terminal makes no requests.
- **Money and trust (AC 7, 11, 12).**
  - The resource lists its fields one by one, so no provider cost, margin, rates or token usage is sent. `305510000` (the Lighthouse's provider cost) is asserted absent from the whole page JSON, including the D-09 row.
  - `user_charge_nanodollars` is sent only for `Succeeded`, so a factory-failed row's stored 0 and the D-09 row read null.
  - The D-09 row (a local `idempotency_key_reused` failure, then a succeeded snapshot) reads Failed, "Not charged", the idempotency copy, no image and no charge, in both PHP and Vitest.
  - Amounts are formatted only through `MoneyAmount` and `AmountRange`, which use `lib/money.ts`. A grep for `toFixed`, `1e9` and `NumberFormat` outside `money.ts` finds only `counting.ts`'s character count.
- **Authorization.** History is scoped by `ownedBy($user)` in the query, and there is no route that takes an id. Before this audit the two-user rows covered only the full visit; see #3.
- **Failure copy (AC 8, D-43).** `GenerationFailureCode` is the one mapping. Its values are spelled exactly as `LocalFailureReason` and Pixelforge's `error.code`. A test makes every `LocalFailureReason` have a case, so PE-013 and PE-014 append a case and its copy in one place. The page renders `failure_message` with text interpolation; there is no `v-html` anywhere in `resources/js`. Length handling was missing; see #1 and #2.
- **Images (AC 7, 9, 10).**
  - An expired URL is never requested. The server withholds `image_url` from exactly `image_url_expires_at` on. HistoryItem passes `src = null` once its server-clock tick passes the expiry, so ResultImage removes the `img`, and a Back restore keeps the last fresh offset (review W1).
  - `loadableUrl` admits only `https://` and root-relative URLs, so a provider URL can't become a `javascript:`, `//host` or `/\host` link.
  - The link has `target="_blank"` and `rel="noopener noreferrer"`, with an sr-only "(opens in a new tab)". The `img`'s `alt` is the prompt.
- **Clock skew (AC 6, 9).** The elapsed time, the relative times and the client expiry flip all read `now` from `useServerClock`, which is offset by `server_time`. `useServerClock.test.ts` and `Index.test.ts` run them with a browser clock 2 min fast.
- **Vue layer.** No `any` appears in `resources/js`, `tests/js` or `tests/e2e`. No file in `components/ui/` or `lib/` imports Inertia; `lib/generationTiming.ts` has no Vue import. The new history components don't import Inertia either. History's page only wires the two composables and its props to the components; the polling and clock logic live in composables.
- **UI polish.** The new states reuse PE-011's grid, Badge tones, radius tokens and MoneyAmount. QA measured no horizontal scroll at 375, 768 and 1440 px in both projects, and the AC 13 spec asserts it at 375 px. The paused note is a fixed pill, so the list never shifts.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | `validation_failed` quoted Pixelforge's `error.message` at any length. `error_param` is capped at 255 characters when it is parsed, but `error_message` is a `text` column, stored as the provider sent it, so a long message filled the card with provider text. | `app/Domain/Generation/Enums/GenerationFailureCode.php:65-77` | Warning | Fixed | `rejectedRequest()` now cuts the message to `MAX_DETAIL_LENGTH` = 300 characters with `Str::limit(…, '…')`. That is multibyte-safe, and the cut is applied after the stored value is read, so nothing stored changes. Two `GenerationFailureCodeTest` rows pin it: a 5,000-character `é` message is cut to 300 plus `…`, and a message of exactly 300 is kept whole (`6338c25`). |
| 2 | The failure message had no word breaking. A provider message holding a long URL or token would overflow its `min-w-0` column and give a 375 px page horizontal scroll. The prompt above it already breaks with `wrap-break-word`. | `resources/js/components/history/HistoryItem.vue:159` | Warning | Fixed | The paragraph now has `wrap-break-word`, like the prompt. A `HistoryItem.test.ts` row with a 200-character URL in the message pins the class (`ce34748`). |
| 3 | No test proved that a poll, a partial reload of `generations`, returns only the signed-in user's rows. The two-user rows in `GenerationHistoryPageTest` cover only the full visit. | `tests/Feature/Generation/GenerationHistoryLiveUpdatesTest.php` | Suggestion | Fixed | Added `test_a_partial_reload_returns_only_the_signed_in_users_generations`. Another user's rows, one of them created between the visit and the reload, are absent from the reload, `meta.total` is 1, and the other user's prompts appear nowhere in its props. With `ownedBy` removed from the controller, the test fails (`d371466`). |
| 4 | Each poll writes the database session and runs a count query, about 20 requests a minute for each open tab with something in progress. A generation stuck non-terminal keeps one tab polling for as long as it is open. | `resources/js/composables/useLiveUpdates.ts` | Suggestion | Deferred | This is D-41's design: requests never overlap, and both queries use the PE-011 index. Giving up on stuck generations is out of scope here, because PE-014-reconciliation-poller resolves them, and the story leaves background-tab throttling out. |
| 5 | The server clock's offset is re-measured only from a `server_time` newer than any seen, so if the server's clock were stepped backwards, the offset would stay on the old reading until the server passed it again. | `resources/js/composables/useServerClock.ts:38-41` | Suggestion | Skipped | This is the review W1 fix, and the trade is deliberate: it is what keeps a Back restore from rewinding the clock. A backward step of the server clock is an operations fault, and its effect is bounded by the size of that step. |
| 6 | ResultImage latches "Image unavailable" after a load error, even if a later poll sends a new URL (review S3). | `resources/js/components/history/ResultImage.vue:21` | Suggestion | Deferred | Nothing re-signs a URL today; the review recorded it for whichever later story does. The latch is also what stops the same dead URL from being requested again on every poll. |

No Critical issues were found. Both Warnings are fixed.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0. Pint passes on 231 files, and Larastan reports no errors. PHPUnit: 1,233 passed (34,600 assertions). ESLint, Prettier and vue-tsc are clean. Vitest: 488 passed in 31 files. |
| E2E, desktop and mobile | `make e2e` | Exit 0. 38 passed and 2 skipped: the designed touch-only and mouse-only rows of `generate.spec.ts`. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-012/audit-check.log` and `.../PE-012/audit-e2e.log`. No real Pixelforge request was made.
