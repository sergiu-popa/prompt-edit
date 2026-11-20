# Plan — PE-012-live-status-results-and-failure-copy

## Approach
`GenerationResource` gains each generation's times, its actual charge (succeeded only), its image with a server-computed expired flag and one server-composed failure message, and the `generations` prop gains `server_time`, all read from our database alone. On History, `useServerClock` ticks a server-aligned "now" that drives the elapsed time, relative times and the expiry flip, while `useLiveUpdates` partially reloads `generations` only while something is in progress, with AC 4's backoff. `HistoryItem` renders each state on PE-011's grid, Badge and MoneyAmount.

## Tasks
Each task is one TDD commit. Write the failing test first, then the code, and get `make check` green before committing. Also run `make e2e` for tasks 6, 7, 9, 10, 12–15, because they touch the History page or a spec. Tasks 6, 7 and 9–14 use the `tailwindcss-development` skill.

Tasks 1–10 are polling plus the queued, processing and succeeded cards (AC 1–7, 12, 13, 17). Tasks 11–16 are the failure copy, D-09, and the expired and unavailable states (AC 8–11, 14–16, 18). The cut point is after task 10 (see Size).

- [x] 1. Add `started_at`, `completed_at` and `user_charge_nanodollars` (succeeded only) to `GenerationResource`, and `server_time` to the collection via `additional()` in `ShowGenerationHistoryController`. Add the fields and `GenerationHistory` to `types/generation.ts`, type Index's prop with it, and update the TS fixtures in `Index.test.ts` and `HistoryItem.test.ts`. Test-first: `GenerationHistoryStatusTest` rows (AC 12, Lighthouse charge), plus the new `ITEM_KEYS` in `GenerationHistoryPageTest`. Commit: `[PE-012] Send each generation's times and actual charge to History`
- [x] 2. Add `image_url`, `image_url_expires_at` and `image_expired` to the resource, with the withholding rules in Story clarifications. Update the TS type and fixtures. Test-first: the expiry and URL rows in `GenerationHistoryStatusTest` (AC 9's server half). Commit: `[PE-012] Send a succeeded generation's image and whether it has expired`
- [x] 3. Add `GenerationHistoryLiveUpdatesTest` (AC 1's page kept, AC 3). No production code change is expected. Commit: `[PE-012] Prove History's partial reload reads only our database`
- [x] 4. Add `formatElapsed` and `formatClockTime` to `lib/relativeTime.ts`, and `lib/generationTiming.ts` with `elapsedSeconds`. Test-first: `relativeTime.test.ts` rows and `generationTiming.test.ts`. Commit: `[PE-012] Format elapsed and completed times`
- [x] 5. Add `composables/useServerClock.ts`. Test-first: `useServerClock.test.ts` with a browser clock 2 min fast. Commit: `[PE-012] Keep a clock aligned with the server's`
- [x] 6. Give HistoryItem a `now` prop: the Processing badge reads `Processing · 14 s`, and the created time reads the server clock. Index passes `now` from `useServerClock`. Widen the status column (see Story clarifications). Loosen `submit.spec.ts`'s status regex to allow the elapsed suffix. Test-first: `HistoryItem.test.ts` rows (AC 5, AC 6). Commit: `[PE-012] Tick a processing generation's elapsed time on the server clock`
- [x] 7. Add `components/history/ResultImage.vue` (the image link only, for now), and give a succeeded card the thumbnail, `Charged`, `Est.` beside it and `Completed`. Test-first: `ResultImage.test.ts` and `HistoryItem.test.ts` rows (AC 7). Commit: `[PE-012] Show a succeeded generation's image and charge on History`
- [x] 8. Add `composables/useLiveUpdates.ts`. Test-first: `useLiveUpdates.test.ts` (AC 1, 2 and 4, fake timers). Commit: `[PE-012] Poll a page's props, backing off after failed polls`
- [x] 9. Wire History: `useLiveUpdates` while any item is non-terminal, the flashed highlight read once at setup, and `components/history/LiveUpdatesNote.vue`. Add `givenReloadsSucceed` / `givenReloadsFail` to the integration `support/inertia.ts`. Test-first: `Index.test.ts` rows. Commit: `[PE-012] Update History live while a generation is in progress`
- [x] 10. Add `tests/e2e/support/liveStatus.ts` and `tests/e2e/live-status.spec.ts` for AC 13 and AC 17, with the highlight kept through live updates and the 375 px check. Commit: `[PE-012] Cover a live success and a slow generation end to end`
- [x] 11. Add `GenerationFailureCode` and the resource's `failure_message` (failed only). Point `LocalFailureReason`'s docblock at it, and add the field to the TS type and fixtures. Test-first: `GenerationFailureCodeTest` and the failure and D-09 rows in `GenerationHistoryStatusTest` (AC 8, 11, 12). Commit: `[PE-012] Explain each failure code in one plain message`
- [x] 12. Give a failed card the Failed badge, "Not charged", its estimate and the message, as text. Test-first: `HistoryItem.test.ts` rows (AC 8, AC 11). Commit: `[PE-012] Show why a generation failed and that it was free`
- [x] 13. Add `isImageExpired` to `lib/generationTiming.ts`, plus ResultImage's expired placeholder and HistoryItem's live flip and note. Test-first: `generationTiming.test.ts`, `ResultImage.test.ts` and `HistoryItem.test.ts` rows (AC 9). Commit: `[PE-012] Show an expired image as a placeholder, live`
- [x] 14. Show "Image unavailable" when the image fails to load or a succeeded generation has no URL. Test-first: `ResultImage.test.ts` rows (AC 10). Commit: `[PE-012] Show Image unavailable instead of a broken image`
- [x] 15. Add AC 14, 16 and 18 to `live-status.spec.ts`, and turn `submit.spec.ts`'s `[blocked]` test into AC 15 (live, no reloads; drop `expectStatusAfterReloads`). Commit: `[PE-012] Cover failures, a duplicate and expiry end to end`
- [x] 16. Add D-41 to D-43 to `requirements.md` §5. Commit: `[PE-012] Record the live status decisions`

## Story clarifications
- **PR size.** The story asks for a split into PE-012a/b. The project rule wins: one PR, with the story's split as the cut point after task 10 (see Size).
- **Where the copy lives (D-43).** It is server-side, like PE-011's `status_label`.
  - The failure code is `local_failure_reason`, else `error_code`. The local reason wins, because PE-013's reasons (`daily_limit` …) differ from their `error_code`s.
  - The resource sends only `failure_message`. No raw code or provider text is sent beside it, because nothing on the page reads them.
- **`validation_failed`.** "Pixelforge rejected the request: {param}: {message}". With no param it is "…: {message}", and with no message too it is "Pixelforge rejected the request." Vue's text interpolation escapes it, and `v-html` is never used.
- **"Configurable" (AC 1)** means `useLiveUpdates`' `intervalMs` option, which defaults to `LIVE_UPDATE_INTERVAL_MS` = 3,000. There is no env setting, because nothing needs one yet. The backoff steps are fixed by AC 4.
- **What counts as a failed poll.** Any finish without `onSuccess` and without a cancel: a 4xx or 5xx (`onHttpException` returns `false`, so Inertia shows no error dialog), a network error (`onNetworkError` returns `false`), or a 409 version or location answer. A cancelled poll changes nothing and is rescheduled at the current delay, unless the page has unmounted.
- **No overlap, no progress bar.** The next poll is scheduled when the previous one finishes, so two never run at once. `router.reload` is async, so Inertia shows no progress bar for it. Background tabs keep polling (out of scope), unlike `usePoll`'s default throttle.
- **Highlight (AC 1).** A partial reload replaces `page.flash` with the response's empty flash. In `@inertiajs/core` 3.11, `setPage` keeps flash only for deferred-prop requests. So Index reads `highlighted_generation_id` once in setup, not in a `computed`. A reload or a pagination click remounts the page, so it still shows only once.
- **Server time (D-42).** `server_time` is ISO-8601 UTC with milliseconds (`2026-11-19T23:59:58.000Z`), placed inside the `generations` prop, so the poll still asks for that one prop. `useServerClock` keeps the offset `Date.parse(server_time) − Date.now()`, re-measured whenever the prop changes.
- **Elapsed (AC 6).** `elapsedSeconds` is `floor((now − started_at) / 1 s)`, clamped at 0, so a provider clock ahead of ours reads `0 s`. `formatElapsed` gives "N s" under 120 s, then "N min" under 120 min, then "N h". Each unit only starts above where the previous one ended, so the count never appears to go back. A processing row with no `started_at` reads just "Processing".
- **Completed (AC 7).** "Completed 2:03 PM" when it was the same day as now in the viewer's zone, else "Completed Nov 12, 2026, 2:03 PM". It is a `<time datetime>` with `formatExactTimestamp` in its `title`.
- **Image fields (AC 9, 12).**
  - All three are null or false unless the generation succeeded.
  - `image_expired` is true when `image_url_expires_at` is set and `now() >= image_url_expires_at`.
  - `image_url` is null when expired, or when it is neither `https://…` nor root-relative (`/…` but not `//…`, which is the fake's form). So a page never requests a dead URL, and a provider URL can never become a `javascript:` link.
- **Charge (AC 7, 12).** `user_charge_nanodollars` is the stored charge for a succeeded generation, else null, including D-09's failed row. Failed cards say "Not charged" from their status. If the actual charge exceeds the estimate (§6), the card shows it as is.
- **Card layout.** The grid builds on PE-011's: status, then content, then time.
  - From `sm`, the columns are `[8.5rem | minmax(0,1fr) | auto]`. Column 1 is wide enough for `Processing · 119 s` on one line and holds the badge, with the result box under it for a succeeded card. Column 2 holds the prompt, the meta, a money row (`[data-charge]` "Charged $0.3819" or "Not charged", then `[data-estimate]`), `[data-completed]`, `[data-image-note]` and `[data-failure-message]`. Column 3 is unchanged.
  - On a phone, the result box comes first at full card width, then the badge and time row, then the text, as in the story's mockup.
  - The badge text is `tabular-nums`, so a ticking count never moves the badge.
- **Result box (ResultImage).** Its size is fixed before the image loads, so a card never jumps.
  - It is `aspect-3/2` at full width on a phone and an 8.5rem square from `sm`. The image is `object-cover` with `loading="lazy"`, `decoding="async"`, `alt` = the prompt, inside `<a target="_blank" rel="noopener noreferrer">` with sr-only "(opens in a new tab)".
  - `data-state` is `image`, `expired` (task 13) or `unavailable` (task 14). The placeholders are a `bg-zinc-50` box with an inset ring and centred `text-xs` copy, in the PE-011 radius tokens.
  - "Images are kept for 24 h after completion." is `[data-image-note]` in column 2.
- **Live expiry.** HistoryItem's `isExpired` is `image_expired || isImageExpired(image_url_expires_at, now)`, so the 1 s server-clock tick flips the card. An image that is already showing is removed, never re-requested. An `<img>` error sets ResultImage's own `unavailable` state, which a later poll doesn't reset, because the card is not remounted.
- **Paused note (AC 4).** It is a fixed pill at the bottom of the viewport (centred on a phone, right-aligned from `sm`), so the list never shifts: "⟳ Live updates paused — retrying…", `text-xs`, white with a ring and `shadow-sm`. Its `role="status"` element is always rendered, and only its content toggles, so screen readers announce it.

## Backend
All classes are `final` (enums aside) and declare `strict_types`.

| Class / file | Location | Role |
| --- | --- | --- |
| `GenerationResource` | `app/Http/Resources/` | Adds, in this order after `created_at`: `started_at`, `completed_at`, `user_charge_nanodollars`, `image_url`, `image_url_expires_at`, `image_expired` and `failure_message` (task 11), with the rules in Story clarifications. A private static `utcIso(?CarbonImmutable)` formats every instant as `Y-m-d\TH:i:s\Z`. The array-shape docblock is updated. It still reads no relation. |
| `ShowGenerationHistoryController` | `app/Http/Controllers/Generation/` | Adds `->additional(['server_time' => …])` to the collection, so Inertia resolves `{data, links, meta, server_time}`. |
| `GenerationFailureCode` | `app/Domain/Generation/Enums/` | String-backed. Cases: `content_policy_violation`, `validation_failed`, `idempotency_key_reused`, `generation_failed` and `internal_error`, spelled as the reason enum and `error.code`. `GENERIC_MESSAGE` is "This generation failed.". `tryFromGeneration(Generation)` takes the local reason, else `error_code`. `message(?string $param, ?string $detail)` returns a case's copy. `messageFor(Generation)` returns the copy, or the generic message. Consumer: `GenerationResource`. |
| `LocalFailureReason` | `app/Domain/Generation/Enums/` | Task 11: the docblock names `GenerationFailureCode` as the copy mapping that PE-013 and PE-014 append to. |

## Frontend
`<script setup lang="ts">` only. Composables clean up with `onScopeDispose`, so they work in a component and in a bare effect scope (unit tests).

| File | Role |
| --- | --- |
| `types/generation.ts` | `Generation` gains the new fields (nullable as the resource sends them). `GenerationHistory` = `Paginated<Generation>` & `{server_time: string}`. |
| `lib/relativeTime.ts` | Task 4: `formatElapsed(seconds)` and `formatClockTime(iso, now, timeZone?)`. Consumer: HistoryItem. |
| `lib/generationTiming.ts` | `elapsedSeconds(startedAt, nowMs)` (task 4) and `isImageExpired(expiresAt, nowMs)` (task 13), with no Vue import. Consumer: HistoryItem. |
| `composables/useServerClock.ts` | `useServerClock(serverTime: MaybeRefOrGetter<string>)` returns a read-only `Ref<number>` of server-now ms, re-aligned when `serverTime` changes and ticked by one 1 s interval. It is cleared on dispose. Consumer: Index. |
| `composables/useLiveUpdates.ts` | `useLiveUpdates({only, active, intervalMs?})` returns `{isPaused}`. It is a `setTimeout` chain over `router.reload({only, onSuccess, onHttpException, onNetworkError, onCancel, onCancelToken, onFinish})`, starting an interval after `active` turns true and stopping when it turns false. After the Nth failure in a row, it waits `BACKOFF_MS` = [6,000, 12,000, 24,000, 30,000][min(N, 4) − 1]. On dispose it clears the timer and cancels an in-flight poll via its token. Consumer: Index. |
| `components/history/ResultImage.vue` | Props `src: string \| null`, `alt`, `expired`. The states and box are in Story clarifications. No Inertia import. Consumer: HistoryItem. |
| `components/history/LiveUpdatesNote.vue` | Prop `paused`. The fixed `role="status"` pill. Consumer: Index. |
| `components/history/HistoryItem.vue` | Task 6: a required `now` prop, the elapsed badge, created time from `now`, and the 8.5rem status column. Task 7: ResultImage, the money row and Completed. Task 12: the failed money row and message. Task 13: `isExpired` and the image note. |
| `pages/Generations/Index.vue` | Prop `generations: GenerationHistory`. Task 6: `now = useServerClock(() => generations.server_time)`, passed to each item. Task 9: `useLiveUpdates({only: ['generations'], active: some item is pending, queued or processing})`, the highlight read once in setup, and `<LiveUpdatesNote :paused>`. |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-41 | History updates through a small composable, `useLiveUpdates`, over `router.reload({ only: ['generations'] })`, not Inertia's `usePoll`. It runs only while a visible generation is non-terminal, waits 3 s from the end of one poll to the start of the next, and never overlaps requests. After a failed poll (an HTTP error, a network error, or any other answer that isn't the page) it keeps the last state, shows a "paused" note, and waits 6 s, 12 s, 24 s, then 30 s between retries. The first success resets it. Unmounting clears the timer and cancels a poll in flight | `usePoll` has one fixed interval and no error hook to back off on, and it throttles background tabs, which this story leaves out. A partial reload of one prop keeps the page, the scroll and each card's state. The prop comes from our database, so the UI never spends Pixelforge's 60 rpm (D-11). |
| D-42 | History measures time against the server's clock: the `generations` prop carries `server_time`, and the page keeps its offset from the browser's clock, re-measured on every response. The server decides `image_expired` (at or after `image_url_expires_at`) and sends no `image_url` once expired, or when the URL is neither `https://` nor root-relative. A 1 s client tick flips a card that expires while it is open | A skewed browser clock can't misstate the elapsed time or keep a dead image up. A page never requests an expired URL, which would log a console error and fail E2E, and a provider URL can never become a `javascript:` link. |
| D-43 | Failure copy lives on the server, in one enum, `GenerationFailureCode`, keyed by the failure code: the local failure reason if there is one, else Pixelforge's `error.code`. The resource sends only the composed `failure_message`, which the page renders as text. An unknown or missing code reads "This generation failed.", and a test requires a case for every `LocalFailureReason` | Like `status_label`, it is decided where the state lives, so PE-013 and PE-014 append a case and its copy in one place. Provider text (`validation_failed`'s `{param}: {message}`) reaches the page only inside that message, and never as HTML. |

## Tests
| Test | Location | Covers |
| --- | --- | --- |
| `GenerationHistoryPageTest` (existing) | `tests/Feature/Generation/` | Task 1 (and tasks 2 and 11): `ITEM_KEYS` and the pending Lighthouse item gain the new fields (null or false). `generations` has exactly `data`, `links`, `meta` and `server_time`. The query-count row still holds. |
| `GenerationHistoryStatusTest` | `tests/Feature/Generation/` | The rows below. |
| `GenerationHistoryLiveUpdatesTest` | `tests/Feature/Generation/` | Task 3, with no `Http::fake()`, so the global `preventStrayRequests()` would throw on any outbound call, and with `Queue::fake()`. From `?page=2`, `reloadOnly('generations')` returns page 2 with `server_time` and no `auth`. A row moved to succeeded in the DB between the visit and the reload shows Succeeded with its charge. `Queue::assertNothingPushed()` (AC 3). |
| `GenerationFailureCodeTest` | `tests/Unit/Domain/Generation/Enums/` | Task 11. It pins the pure mapping table and its edge cases more cheaply than the HTTP path can: each code's message; `validation_failed` with a param and message, a message only, and neither; an unknown code and a null code read the generic message; the local reason wins over a different `error_code`; every `LocalFailureReason` value is a case. |
| `relativeTime.test.ts` (existing) | `tests/js/unit/lib/` | Task 4: `formatElapsed` at 0, 14, 119, 120, 7,199 and 7,200 s; `formatClockTime` on the same day and on another day, in UTC. |
| `generationTiming.test.ts` | `tests/js/unit/lib/` | Task 4: `elapsedSeconds` at 14.9 s, and negative clamps to 0. Task 13: `isImageExpired` 1 ms before, exactly at, and with null. |
| `useServerClock.test.ts` | `tests/js/unit/composables/` | Task 5 (fake timers): with the browser 2 min fast, `now` equals the server time, then +1 s after one tick. A new `server_time` re-aligns it. Disposing the scope stops the ticks. |
| `useLiveUpdates.test.ts` | `tests/js/unit/composables/` | The rows below. |
| `HistoryItem.test.ts` (existing) | `tests/js/integration/components/history/` | The rows below. |
| `ResultImage.test.ts` | `tests/js/integration/components/history/` | Task 7: link `href`, `target="_blank"`, `rel`, `alt` = prompt, lazy, and the box classes. Task 13: expired shows "Image expired" with no `img` or `a`. Task 14: an `error` event shows "Image unavailable", and a null `src` does too. |
| `Index.test.ts` (existing) | `tests/js/integration/pages/Generations/` | The rows below. |
| `live-status.spec.ts` | `tests/e2e/` | The rows below. Every test registers its own user, submits through the form, sets a no-reload marker on `window`, and asserts it is still set at the end. There are no fixed sleeps: only web-first assertions and `expect.poll`, with timeouts from `support/liveStatus.ts`. |
| `submit.spec.ts` (existing) | `tests/e2e/` | Task 6: the first test's status regex allows `Processing · N s`. Task 15: the `[blocked]` test becomes AC 15. |

`GenerationHistoryStatusTest` rows:
- **Task 1.** A processing row has `started_at` and null `completed_at` and charge. The Lighthouse (`createGenerationFor` plus `lighthouseSucceededSnapshotFor` through the transition) has charge 381,887,500, `completed_at` `2026-11-18T23:59:59Z`, and no `provider_cost`, rates or tokens. `305510000` appears nowhere in the page JSON. A factory-failed row has a null charge (the stored 0 is not sent). `server_time` equals the frozen now, with milliseconds.
- **Task 2.** At `2026-11-19T23:59:58Z` the Lighthouse is not expired and has its URL. At `…59Z` it is expired with a null URL and the expiry still sent. A root-relative URL is sent. A `javascript:` URL and a protocol-relative `//host/a.png` are sent as null. Non-succeeded rows have null and false.
- **Task 11.** A local content-policy row, a local `validation_failed` row with `prompt` and with no param, a provider `generation_failed` row, a provider `internal_error` row, an unknown `error_code`, and a null code each give their message, and non-failed rows give null. The D-09 row (a local `idempotency_key_reused` failure, then the succeeded Lighthouse snapshot) has status `failed`, the idempotency copy, a null charge and image, and no `305510000` anywhere.

`useLiveUpdates.test.ts` rows (node env, fake timers, `vi.spyOn(router, 'reload')` driving the callbacks):
- Active at start: nothing before 3 s, then one reload with `only: ['generations']`, and the next 3 s after it succeeds.
- Inactive at start: no reload in 60 s (AC 2). Active turning false after a success: no more reloads.
- A poll that never finishes: no second reload.
- An HTTP error, then a network error, then more errors: waits of 6, 12, 24, 30 and 30 s, with `isPaused` true from the first error. A success: `isPaused` false, and the next wait is 3 s (AC 4).
- `onHttpException` and `onNetworkError` return `false`. A cancelled poll is rescheduled with no change to `isPaused`.
- Disposing while waiting: no reload after. Disposing mid-flight: the token's `cancel` is called, and the late `onFinish` schedules nothing (AC 2).

`HistoryItem.test.ts` rows:
- **Task 6.** Pending and queued read "Queued". Processing with `started_at` 14 s before `now` reads "Processing · 14 s", and with `now` 1 s later it reads "15 s". Processing with no `started_at` reads "Processing". The created time follows `now`, not the browser clock.
- **Task 7.** A succeeded card reads "Charged $0.3819", and its tooltip is "$0.381887500" on hover and on focus. It shows "Est. $0.3478 – $0.4572" in the same row, "Completed …" with `datetime`, and ResultImage with the URL. A queued card has no `[data-charge]` or ResultImage.
- **Task 12.** Failed shows Failed in the danger tone, "Not charged", `Est. …` and the message. A message holding `<b>x</b>` renders literally, with no `b` element. The D-09 shape (failed, null charge and image) has no ResultImage or "Charged".
- **Task 13.** `image_expired` true shows the expired box and the note. An unexpired card whose `now` passes `image_url_expires_at` flips to expired without a remount (same root element).

`Index.test.ts` rows (task 9, fake timers):
- With one processing item, `router.reload` is called after 3 s with `only: ['generations']`. With all items terminal, it is never called.
- `setProps` with the same ids and one status changed keeps every item's root element (`toBe`), and an expanded prompt stays expanded (AC 1).
- With the flash given at mount, then cleared as a partial reload clears it, plus `setProps`, the item stays highlighted.
- A failed reload shows "Live updates paused — retrying…" in the `role="status"` element, and a later success clears it (AC 4).
- Unmounting stops reloads and the clock (AC 2).
- `now` reaches the items from `server_time`, so a processing item reads the server-clock elapsed time with a skewed browser clock (AC 6).

`live-status.spec.ts` rows (desktop and mobile projects):

| AC | Prompt (Medium, Square unless noted) | Expect |
| --- | --- | --- |
| 13 | `[succeed] A lighthouse on a cliff at dawn, oil painting`, High, Landscape | Live Queued or Processing, then Succeeded. The image is visible with alt = the prompt and an `href` that opens in a new tab. The exact charge from its tooltip lies within the exact estimate tooltips. "Just submitted" is still on the item. At a 375 px viewport, the card's contents sit inside its box (`expectInsideContentBox`), with no horizontal scroll. |
| 17 | `[slow] A lighthouse on a cliff at dawn, oil painting` | It reads `Processing · N s`, and `expect.poll` sees a larger N later. Then Succeeded. |
| 14 | `[fail] A lighthouse on a cliff at dawn, oil painting` | Failed, the `generation_failed` copy, "Not charged" and `Est. …`. |
| 15 (submit.spec) | `A [blocked] castle` | Failed live, the content-policy copy, "Not charged", `Est. $0.0582 – $0.0863`. |
| 16 | `[duplicate] A lighthouse on a cliff at dawn, oil painting` | Succeeded, exactly one item, and a charge within its estimate. |
| 18 | `[expire] A lighthouse on a cliff at dawn, oil painting` | Succeeded, then "Image expired" and "Images are kept for 24 h after completion.", with no `img` in the card and no reload. The fixture's console-error check is the "no dead request" proof. |

`tests/e2e/support/liveStatus.ts` holds:
- the fake's dev defaults from `config/pixelforge.php` (queue 1 s, processing 3 s, slow 10 s, `[expire]` TTL 5 s), the worker's `--sleep=3` and the 3 s poll, each named and commented with its source;
- the timeouts derived from them, with a 2× margin for parallel workers;
- `submitPrompt(page, prompt, options?)`, `markNoReload` / `expectNoReload`, and `exactNanodollars(locator)`, which parses a tooltip's `$0.381887500` into an integer.

| AC | Proven by |
| --- | --- |
| 1 | `useLiveUpdates.test.ts`, `Index.test.ts` (non-remount, highlight), `GenerationHistoryLiveUpdatesTest` (page kept), E2E highlight |
| 2 | `useLiveUpdates.test.ts`, `Index.test.ts` |
| 3 | `GenerationHistoryLiveUpdatesTest` |
| 4 | `useLiveUpdates.test.ts`, `Index.test.ts` |
| 5–6 | `HistoryItem.test.ts`, `relativeTime.test.ts`, `generationTiming.test.ts`, `useServerClock.test.ts`, `Index.test.ts`, E2E AC 17 |
| 7 | `HistoryItem.test.ts`, `ResultImage.test.ts`, `GenerationHistoryStatusTest`, E2E AC 13 |
| 8 | `GenerationFailureCodeTest`, `GenerationHistoryStatusTest`, `HistoryItem.test.ts`, E2E AC 14–15 |
| 9 | `GenerationHistoryStatusTest`, `generationTiming.test.ts`, `ResultImage.test.ts`, `HistoryItem.test.ts`, E2E AC 18 |
| 10 | `ResultImage.test.ts` |
| 11 | `GenerationHistoryStatusTest`, `HistoryItem.test.ts` |
| 12 | `GenerationHistoryPageTest`, `GenerationHistoryStatusTest` |
| 13–18 | `live-status.spec.ts`, `submit.spec.ts` |

## Size
The estimate is ≈ 2,300 changed lines, about 60 % of them tests. The story's own ~700 counted no layout work, test helpers or E2E support. This is far above the ≈ 400 budget, and it ships as one PR of self-contained commits, by the project rule.

| Tasks | Content | ≈ Lines |
| --- | --- | --- |
| 1–3 | Resource fields, server time, partial-reload proof | 420 |
| 4–7 | Time helpers, server clock, processing and succeeded cards | 560 |
| 8–10 | Polling composable, History wiring, note, E2E (AC 13, 17) | 650 |
| 11–12 | Failure mapping, D-09, failed card | 340 |
| 13–15 | Expired and unavailable states, E2E (AC 14–16, 18) | 320 |
| 16 | Decisions | 15 |

**Cut point: after task 10.** PR A is polling plus the queued, processing and succeeded cards (AC 1–7, 12, 13, 17). It already withholds expired URLs server-side, so it never requests a dead image. PR B is tasks 11–16 (AC 8–11, 14–16, 18).

## Risks
- **The flash.** A `computed` over `page.flash` would drop the highlight on the first poll (see Story clarifications). `Index.test.ts` clears the flash before `setProps` to prove it.
- **The status regex.** `submit.spec.ts` matches `^(Queued|Processing|Succeeded)$`, so task 6's `Processing · N s` would break it. Loosen it in the same commit.
- **`[expire]` timing.** The fake's URL dies 5 s after completion, and the page sees success within one 3 s poll, which leaves about 2 s to load the image. If the image is requested after its URL dies, the 403 logs a console error and the test fails. If that flakes, raise the `expire_ttl_seconds` default rather than adding waits, and re-derive the timeout.
- **E2E timings follow `.env`.** `make e2e` uses the dev `.env`, so a developer's non-default `PIXELFORGE_FAKE_*` values break the derived timeouts. `support/liveStatus.ts` says so.
- **Node environment.** `useLiveUpdates.test.ts` and `useServerClock.test.ts` run in the `unit` project (node). If importing `@inertiajs/vue3` needs a DOM there, add `// @vitest-environment jsdom` to that file only.
- **The ticking clock** re-renders every item once a second. That is cheap at 20 items, but no item may re-create its `img`: the list stays keyed by id, and ResultImage keeps its `src` binding stable.
- **The provider clock.** `started_at` and `image_url_expires_at` are Pixelforge's instants, while the offset is ours. A small skew between the two servers can read `0 s` for a moment (clamped), or show "Image unavailable" briefly before the expiry flip.
- **Type fixtures.** Every new required field in `Generation` must be added to the `Index.test.ts` and `HistoryItem.test.ts` fixtures in the same commit, or `vue-tsc` fails `make js-check`.
- **Production.** Generate is closed until PE-015 (D-40), so production History stays empty, and nothing polls there yet. The post-deploy smoke step (D-19) is unchanged.
