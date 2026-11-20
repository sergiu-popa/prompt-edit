# QA — PE-012-live-status-results-and-failure-copy

**Verdict: QA PASSED**

Run on branch `pe-012-live-status-results-and-failure-copy` at `a47bd13`. Prerequisites checked: story status `qa-ready`, review round 2 verdict `APPROVE`, no Critical review items open.

Scratch root: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-012/`

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` | Exit 0. Pint and Larastan clean. PHPUnit 1,230 passed (34,574 assertions). Vitest 487 passed in 31 files. |
| Existing E2E (desktop + mobile) | `make e2e` (build + Playwright) | Exit 0. 38 passed, 2 skipped (the designed touch-only and mouse-only rows). |
| Temporary browser pass (desktop + mobile, 375 / 768 / 1440) | `docker compose run --rm playwright npx playwright test tests/e2e/qa-shots.spec.ts` on the in-container `npm run build` output | 6 live-transition runs and 6 failing-poll runs, all passed in the final runs. Spec copied to scratch, then deleted from the repo. |

Logs: `.../PE-012/make-check.log` (scratch root), `.../PE-012-e2e.log`, `.../PE-012-qa-run5.log` (live), `.../PE-012-qa-run6.log` (paused).

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 polls every 3 s, `generations` prop only, keeps page, scroll, highlight; unchanged cards not re-mounted | `useLiveUpdates.test.ts` (3 s cadence), `Index.test.ts` ("keeps every card…", "keeps the just-submitted highlight…"); browser: 2 polls, scroll 200 → 200, highlight kept, 6/6 card nodes kept in all 6 live runs | Pass |
| 2 stops when all terminal and on unmount; never polls an all-terminal page | `useLiveUpdates.test.ts` ("never polls while nothing is active", "stops once active turns false", "makes no poll after its scope is disposed"); `Index.test.ts` ("never polls a page whose generations have all finished", "stops polling and the clock once the page unmounts"); browser: 0 partial requests in the 10 s after all-terminal, 12 of 12 runs | Pass |
| 3 polls never call Pixelforge | `GenerationHistoryLiveUpdatesTest` (partial reload of a later page, no `Http::fake()`, stray HTTP prevented) | Pass |
| 4 poll failure keeps last state, shows note, backs off 6 / 12 / 24 / 30 s, recovers to 3 s | `useLiveUpdates.test.ts` ("keeps the last state… backs off 6, 12, 24, then 30 s", "recovers on the next success"); `Index.test.ts` ("says live updates are paused…"); browser: note visible, failure gaps 6.0 s and 12.0 s (3 forced 500s per run), last-known statuses unchanged at each failure, note cleared on recovery in 6 of 6 runs | Pass (24 s and 30 s steps are unit-level only) |
| 5 Queued badge | `HistoryItem.test.ts`; browser: "Queued" seen on every card at submit | Pass |
| 6 `Processing · N s` on the server clock | `useServerClock.test.ts` ("reads the server's time, not the skewed browser clock"), `Index.test.ts` ("times a processing generation on the server's clock, though the browser's is 2 min fast"); browser: counter ticks in 1 s steps, e.g. `Processing · 1 s` → `· 12 s` | Pass |
| 7 succeeded card: thumbnail opens new tab, `Charged`, exact value on hover/focus, `Est.`, completed time | `ResultImage.test.ts` (new tab, alt text), `HistoryItem.test.ts` (exact `381,887,500` n$ as `$0.381887500`); browser: `Charged` within `Est. $0.3478 – $0.4572` for all succeeded cards, exact tooltip read back, hover screenshot taken | Pass (see note 1) |
| 8 one failure message per code, `Not charged` | `GenerationFailureCodeTest`, `GenerationHistoryStatusTest::test_a_failed_generation_explains_itself_in_one_message`; browser: `generation_failed` copy and content-policy copy exact, `Not charged`, no image | Pass |
| 9 expiry at or after `image_url_expires_at`; placeholder; no URL request; live flip | `GenerationHistoryStatusTest` (one second before: sent; exactly at: expired and URL withheld); `ResultImage.test.ts` ("drops a showing image once it expires"); browser: `[expire]` showed `image` then `expired` at 14.2 s with no reload, and its URL was never requested after the flip (see Visual verification) | Pass |
| 10 "Image unavailable" on load error or no URL | `ResultImage.test.ts` (load error, latched on repeat URL, no-URL case) | Pass (JS; dead URLs are not driven in E2E by design) |
| 11 locally failed, later succeeded at Pixelforge (D-09): Failed, Not charged, no image | `GenerationHistoryStatusTest::test_a_local_failure_that_later_succeeded_at_pixelforge_still_reads_failed_and_free` | Pass |
| 12 no provider cost or margin in props; charge only when succeeded | `GenerationHistoryStatusTest::test_the_succeeded_lighthouse_shows_its_actual_charge_and_times_and_never_the_provider_cost`, `test_a_failed_generation_is_sent_without_a_charge…` | Pass |
| 13 E2E `[succeed]` live to Succeeded, image shown, charge within estimate | `live-status.spec.ts` in `make e2e`; temporary pass: all 6 live runs | Pass |
| 14 E2E `[fail]` live to Failed, `generation_failed` copy, Not charged | `live-status.spec.ts` in `make e2e`; temporary pass | Pass |
| 15 E2E `A [blocked] castle` live to Failed, content-policy copy | `submit.spec.ts` in `make e2e`; temporary pass (failed at 0.9 to 1.0 s, local) | Pass |
| 16 E2E `[duplicate]` one History item, one charge within estimate | `live-status.spec.ts` in `make e2e`; temporary pass: count 1, one `Charged` line | Pass |
| 17 E2E `[slow]` stays Processing, elapsed rises, then Succeeded | `live-status.spec.ts` in `make e2e`; temporary pass: `Processing · 1 s` → `· 12 s` → Succeeded | Pass |
| 18 E2E `[expire]` shows Image expired live, no console error | `live-status.spec.ts` in `make e2e`; temporary pass: flip at 14.2 s, no console error | Pass |

## Edge cases probed

- **Expiry while polls are paused.** The `[expire]` card went from its image straight to `Image expired` on the recovery poll, because the server computed `image_expired` after the TTL. This is the correct server-side path, and no dead URL was requested.
- **Duplicate prompts in one History.** Each generation is its own card; `[duplicate]` shows one item and one charge.
- **Polling after the recovery poll returns all-terminal.** Polling correctly stopped, with zero further requests (the paused runs).
- **Browser-clock skew.** The elapsed counter follows the server clock (unit and integration tests), and the browser pass reads consistent counts.
- **Forced 500 console lines.** Chromium logs each forced 500 as a console error. Exactly 3 lines per paused run, one per forced failure, all matched. No other console or page errors.

## Visual verification

Screens touched: History (`/generations`), live, signed in as fresh E2E users. Measured at 375, 768 and 1440 px in both the Chromium desktop and the Pixel 7 mobile projects, using viewport resizing inside the test.

- **Horizontal scroll** (`document.documentElement.scrollWidth` vs `clientWidth`): equal at every measured state. Final live state: 375/375, 768/768, 1440/1440 (both projects). Paused state: equal at all three widths. Also asserted in the spec at each phase.
- **Scroll survival:** with the viewport shortened to 360 px, the page scrolls 464 to 872 px. Scroll stayed at 200 px across two successful polls in all six live runs. The highlighted card stayed highlighted and the card nodes stayed mounted (6 of 6).
- **Live transitions** (wall-clock from submit, live runs, 375 desktop): `[blocked]` Queued → Failed at 0.9 s; `[fail]` Queued → Processing · 2 s → Failed at 11.1 s; `[succeed]` Queued → Processing · 3 s → Succeeded at 8.1 s; `[duplicate]` Succeeded at 11.1 s; `[slow]` Processing · 1 s through · 12 s → Succeeded at 20.4 s; `[expire]` image at 11.2 s, Image expired at 15.9 s. Values vary by run; the sequence is the same in all six.
- **Paused note:** the note appeared after the first failed poll and sat over the last known cards at every width. The screen read "Live updates paused — retrying…" with each card's last state.
- **Console:** 0 errors in all live runs; the paused runs logged only the 3 forced-500 lines.
- **Layout:** both screens read cleanly at 375 and 1440 px (checked visually). No overlap, and the expired placeholder and failure copy sit in place.

Screenshots (scratch, not in repo), under `.../PE-012/shots/`:
- Live runs: `<width>-<project>-01-just-submitted.png`, `-02-in-flight-after-polls.png`, `-03-all-terminal.png`, `-04-hover-exact-charge.png`, `-05-final-full.png`
- Paused runs: `<width>-<project>-01-submitted.png`, `-02-paused-note.png`, `-03-recovered-final.png`

Measurements (JSON, same folder): `<width>-<project>-live-report.json` and `<width>-<project>-paused-report.json`.

## Notes (not blocking)

1. **Exact charge example.** AC 7 and AC 13 cite `Charged $0.3819` for the Lighthouse. The fake seeds each generation's usage from its idempotency key, so the charge differs per submission (observed `$0.3504` to `$0.4465`, always inside `Est. $0.3478 – $0.4572`). The exact `$0.381887500` display is asserted in `HistoryItem.test.ts`. The browser pass checks format and range, as AC 13 asks. Suggest the story wording say "a charge within the estimate" for the E2E claim.
2. **Backoff steps 24 s and 30 s** are verified only in `useLiveUpdates.test.ts`. The browser pass waited for the 6 s and 12 s steps.
3. **Expiry while paused** shows `Image expired` on the recovery poll rather than through the client timer. The server computes the flag (AC 9), so this is correct, but the client-timer path is covered only by the live `[expire]` E2E and `ResultImage.test.ts`.

## Data created

The temporary pass created **180 generations** under **42 E2E users** (`e2e-desktop-*` and `e2e-mobile-*` registered by the spec). Their hold and settlement ledger rows also exist. The ledger is append-only, so nothing was deleted, as the story notes. `make check` and `make e2e` created their own users, which were not counted.
