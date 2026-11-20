# QA — PE-018-usage-reconciliation-and-final-signoff

## Round 1

**Verdict: QA PASSED**

Run on `49a9ed2` (lane l18, fake Pixelforge driver). QA run 2: run 1 stopped before writing a verdict. Every command here ran in the foreground.

Acceptance criteria 15–17 are the orchestrator's, after merge (see the AC table). They are not QA blockers.

### Suite

| Check | Result |
|---|---|
| `lane-make.sh l18 check` (lint, stan, test, js-check, js-test) | exit 0 |
| Pint | clean, 335 files |
| Larastan | No errors |
| PHPUnit | 1971 passed (38403 assertions) |
| ESLint, Prettier, vue-tsc | clean |
| Vitest | 539 passed in 37 files |
| `restart-worker`, then full `e2e` | 48 passed, 2 skipped |

The 2 skips are by design: `generate.spec.ts:145` is touch-only (desktop) and `:172` is mouse-only (mobile).

Logs: `lane-make.sh l18 logs` shows 300 lines with no 500s.

Full output: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-018/check.log` and `.../PE-018/e2e-full.log`.

### Acceptance criteria

| AC | Evidence | Result |
|---|---|---|
| 1 Contract, `GET /v1/usage`, typed errors, budget | `HttpPixelforgeUsageTest`, `ThrottledPixelforgeClientTest`, `DateRangeTooLong` mapping (`SubmitAttemptOutcomeTest`, `PixelforgeApiExceptionTest`) | Pass |
| 2 `cost_usd` to n$, exact, refusals | `UsageReportDataTest` (worked values, refusal matrix) | Pass |
| 3 Fake usage from its own state, zero days, 422 over 92 | `FakePixelforgeUsageTest`, parity tests | Pass |
| 4 Per-day five figures, Pixelforge beside ours | `UsageComparisonDataTest`, `ShowOperatorReportUsageTest`; browser baseline (below) | Pass |
| 5 Exact integer Match/Mismatch, counts ignored, totals badge | `UsageFigures` and `UsageComparisonDataTest` (count-only, token-only, 1 n$ cases); browser: 30/30 Match and total Match | Pass |
| 6 Worked example | `UsageComparisonDataTest::givenTheWorkedExample...` | Pass |
| 7 +1 n$ gives Mismatch on day and total | `test_one_nanodollar_more_mismatches...` | Pass |
| 8 Realistic mismatch deltas and copy | `test_a_generation_made_outside_the_app...`, JS `UsageComparisonTable.test.ts` | Pass (PHP and JS only, see below) |
| 9 "In progress" on today only | `test_only_today_is_in_progress`; browser: row `2026-11-20 In progress` | Pass |
| 10 Zero days match | `test_zero_days_match`, `test_zero_is_zero_everywhere` | Pass |
| 11 Range rules; 93 days sends nothing; 422 gives notice | `ShowOperatorReportUsageTest` (`Http::assertNothingSent`, line 133); `test_a_range_refused_by_pixelforge...`; browser: 93 days gives field error | Pass |
| 12 Mismatch explanation copy | JS `UsageComparisonTable.test.ts`, `Show.test.ts` | Pass (not reachable in the browser) |
| 13 Cache successes only, 60 s | `FetchPixelforgeUsageActionTest`, `test_two_loads_send_one_request`; failures not cached | Pass |
| 14 Unavailable: our figures, "—", notice, logged without secrets | `test_pixelforge_unavailable_shows_our_figures_only`, `test_any_failure_keeps_our_figures_and_reaches_no_one`, `test_the_api_key_appears_in_no_log_or_exception` | Pass (not reachable in the browser) |
| 15 Production sign-off: redeploy, PE-015 set recreated | orchestrator, after merge | Orchestrator, after merge |
| 16 Production every day Match | orchestrator, after merge | Orchestrator, after merge |
| 17 Read-only production smoke | `tests/smoke/production.spec.ts`: credentials from `E2E_REVIEWER_EMAIL` and `E2E_REVIEWER_PASSWORD`; non-GET requests recorded and must be exactly `POST /login` and `POST /logout`; no generations, no purchases. The spec was not run here (the lane has no production URL). | Orchestrator, after merge |
| 18 README final pass | Verified in review round 2 (`3d29d35`, `2061f37`); not a QA test | Pass (review) |

### Edge cases and regressions

- Range boundaries: 93 days (2026-08-20 to 2026-11-20) is refused with the field error. 92 days (2026-08-21 to 2026-11-20) is accepted and renders 92 day rows. Each is checked at 375, 768 and 1440 in both projects.
- Unauthorized access: a non-operator gets 403 on `/report`. Covered by `report.spec.ts`, which passes.
- Regressions: PE-016 `report.spec.ts`, `report-usage.spec.ts`, `live-status`, `submit`, `history`, `auth` and `welcome` specs all pass.
- Unavailable, mismatch and 422 states are not reachable from the fake in a browser. They are covered by the PHP and JS tests named above.

### Visual verification

Probe spec: a temporary `tests/e2e/pe018-probe.spec.ts`, run in both the desktop and mobile Playwright projects, with explicit viewports of 375, 768 and 1440. The file was deleted afterwards, and `git status` is clean.

Measured:

- **Horizontal page scroll:** `documentElement.scrollWidth - clientWidth` = 0 px at every width, in both projects, for the baseline, the 93-day state and the 92-day state.
- **Table scroll:** the reconciliation table's own wrapper scrolls sideways inside its card, as designed. Its scroll width is 1116 px against a client width of 341 px at 375 px, 718 px at 768 px and 974 px at 1440 px. The card is 343, 720 and 976 px wide.
- **Baseline (default 30-day range, 2026-10-22 to 2026-11-20):** 30 day rows, every day Match, total Match. Today shows "In progress" with a Match badge and a Δ of 0.
- **Exact values:** hover and focus on today's Pixelforge and recorded cost cells, and on the total's recorded cell, each show `$6.364507500` (9 decimals). Escape closes the tip.
- **93-day range:** the "To" field is `aria-invalid="true"` and shows "The range can be at most 92 days." at all three widths. The page re-renders with the default 30-day range (302 then 200). The browser cannot see usage calls because the fake is in-process, so the no-request claim rests on the `Http::assertNothingSent()` test named above.
- **92-day range:** `200`, 92 rows, no error, Match on today, total Match, no horizontal overflow at any width.

Demo reconciliation (one `[succeed]` generation per project run, so two in total):

- The operator's today "recorded" went from 6,335,492,500 n$ to 6,364,507,500 n$, a delta of 29,015,000 n$, and Pixelforge matched the recorded value after the Succeeded state. The first reload showed Match, so `pollMs` was 122 ms.
- The database confirms the two generations' provider costs: 13,515,000 n$ and 15,500,000 n$, which sum to 29,015,000 n$.
- Caveat: the story said "one generation". Both projects ran the probe in parallel, so two generations were created. The totals still reconcile exactly.

Screenshots (not in the repo): `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-018/shots/`, named `{desktop,mobile}-{375,768,1440}-{baseline,range92,range93}.png`, plus the `*.json` measurements.

Browser console errors: none. The E2E fixture fails any test on a console error or page error, and no probe test failed.

### Cleanup

- The probe spec is deleted. The fixture rows it created are the two demo generations noted above, which are real fake records and are kept as evidence.
- No repo changes beyond `qa.md` and the status change.

### Findings

None blocking.
