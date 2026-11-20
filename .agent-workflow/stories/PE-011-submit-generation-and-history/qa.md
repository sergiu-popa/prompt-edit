# QA — PE-011-submit-generation-and-history

**Verdict: QA PASSED**

Run on branch `pe-011-submit-generation-and-history` at `8f8bc76`. Prerequisites checked: story status `qa-ready`, review round 2 verdict `APPROVE`. No Critical review items are open.

Scratch root: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-011/`

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` | Exit 0. Pint and Larastan clean. PHPUnit 1,182 passed (34,185 assertions). Vitest 417 passed in 27 files. |
| Existing E2E (desktop + mobile) | `make e2e` (build + Playwright) | Exit 0. 24 passed, 2 skipped (the designed touch-only and mouse-only rows). |
| Temporary browser pass (desktop + mobile) | `docker compose run --rm playwright npx playwright test tests/e2e/qa-shots.spec.ts` on the `npm run build` output | 10 of 10 passed in one run. Spec deleted before commit. |

Logs: `.../PE-011/make-check.log`, `.../PE-011/e2e-baseline.log`, `.../PE-011/qa-shots-final.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 prompt required, whitespace rejected, 4,000 code points accepted, 4,001 rejected | `CreateGenerationValidationTest` (4,000 code points is accepted whatever its bytes; unicode whitespace is blank); direct POST probe returned `prompt` error for 4,001 `a` | Pass |
| 2 size and quality are exact enums | `ImageSizeTest` (`test_rejects_a_string_that_is_not_an_exact_size`), the quality enum unit test, and the feature validation matrix in `CreateGenerationValidationTest` | Pass |
| 3 reference rules (max 4, http(s), 2,048, shape only, blanks dropped client side) | `CreateGenerationValidationTest`, `CreateGenerationTest` (`test_reference_urls_are_trimmed`, `test_a_duplicated_reference_is_stored_and_billed_twice`), `generationForm.test.ts`, `referenceImages.test.ts`. Direct POST probe: `reference_images.0`, `.1` and `.3` rejected (ftp, javascript, blank), `.2` accepted. | Pass |
| 4 client cannot set money, owner or metadata | `test_fields_beyond_the_form_are_ignored`. Direct POST probe sent `estimate` and `user_id` and got no error for them; the DB shows the server's estimate and owner. | Pass |
| 5 validation failure creates nothing and shows inline errors | `test_a_rejected_field_sends_the_user_back_with_its_error_and_creates_nothing`. Browser: the probe user has 0 generations. | Pass |
| 6 valid Lighthouse creates one generation, one hold of −457,150,000 n$, one job, redirect to History highlighted | `test_lighthouse_creates_one_pending_generation_with_the_server_estimate_its_hold_and_its_job`; browser: `e2e-desktop-2` and `e2e-mobile-7` each hold −457,150,000 for their Lighthouse generations | Pass |
| 7 stored estimate equals page (Unicode 457,156,250; leading spaces 457,156,250 kept everywhere) | `test_a_unicode_prompt_stores_the_max_the_page_showed`, `test_leading_spaces_are_kept_in_the_stored_prompt_the_estimate_and_the_hold`; browser: the Lighthouse rows store 457,150,000 (single-line prompt, as specified) | Pass |
| 8 double submit creates one generation; a new form submits a second | `test_the_same_form_submission_twice_creates_one_generation_one_hold_and_one_job`, `test_a_new_form_submission_of_the_same_prompt_creates_a_second_generation`, `CreateGenerationActionConcurrencyTest`; E2E `submit.spec.ts` double-click row; browser double-click: 1 generation for desktop and for mobile | Pass |
| 9 Generate disabled while invalid or in flight | `Create.test.ts`, `generationForm.test.ts`; browser: disabled for empty, over-limit (4,001), bad URL and not-configured states | Pass |
| 10 throttle at 10/min, inline message with seconds, creates nothing | `CreateGenerationThrottleTest` (`test_the_eleventh_submission_in_a_minute_is_refused_inline_and_creates_nothing`, `test_submissions_work_again_once_the_minute_has_passed`, `test_the_limit_is_configurable`). Not driven in the browser. | Pass (PHP and JS) |
| 11 guest redirected to sign in, nothing created | `CreateGenerationTest::test_a_guest_is_sent_to_sign_in_and_nothing_is_created`, `GenerationHistoryPageTest` guest rows | Pass |
| 12 History owner-scoped, newest first, 20 per page, empty state for a user with none | `test_history_lists_only_the_signed_in_users_generations`, `test_a_users_generations_come_newest_first`, `test_history_shows_twenty_generations_a_page`, `test_a_user_with_no_generations_sees_none_even_when_another_user_has_some`; browser: empty state for new users | Pass |
| 13 item fields (clamp, quality/size, reference count, badge incl. Queued for pending, range, relative time) | `HistoryItem.test.ts`; browser: all fields on the 375 and 768 lists and the 1440 highlight (see Visual verification) | Pass. The clamp and expand were not exercised with a long prompt in the browser. |
| 14 highlight once after redirect, not after reload | `test_history_highlights_the_generation_just_created`, `test_a_plain_visit_highlights_nothing`, `test_a_replay_highlights_the_first_generation`; E2E reload row; browser: "Just submitted" tag on the redirect only | Pass |
| 15 empty state links to Generate; History nav link active | E2E `history.spec.ts`; browser: `aria-current="page"` on the History link at all three widths, and the Generate and History pills render as expected | Pass |
| 16 explicit API Resource, integer n$, no provider cost, constant query count | `test_an_item_holds_exactly_the_resources_fields_with_integer_nanodollars`, `test_the_query_count_does_not_grow_with_the_page`; `resources/js/types` matches | Pass |
| 17 E2E: fresh user, Lighthouse, History with highlight and range | `submit.spec.ts` (AC 17); browser: `e2e-desktop-2` and `e2e-mobile-7` | Pass |
| 18 `[blocked]` castle shows Failed after reload, 58,150,000–86,287,500 range | `submit.spec.ts` (AC 18); browser: `A [blocked] castle` stored with max 86,287,500 and status failed | Pass |
| 19 double-click gives one History item | `submit.spec.ts`; browser: count 1 for desktop and mobile, verified in the DB | Pass |

## Edge cases probed

- **Server authority, direct POST (signed in, no client):** 4,001-code-point prompt, `ftp://`, `javascript:alert(1)`, blank reference, and `estimate` plus `user_id` extras. Result: HTTP 422 with `prompt`, `reference_images.0`, `.1`, `.3`; the valid `https` reference at index 2 passed; the extras were ignored. The response was JSON because the probe used `Accept: application/json`. The probe user has 0 generations.
- **Client-side bypass:** Generate's `disabled` was removed with `evaluate`, then the button was clicked with bad input. No POST was sent, so the client guard holds. The URL field stayed in its error state.
- **Unicode and whitespace parity:** covered by the feature tests above. Not separately probed in the browser.
- **Concurrency:** `CreateGenerationActionConcurrencyTest` runs two real connections.
- **Not-configured:** the `can_submit` prop was flipped to `false` in the page HTML inside the browser (Playwright route interception). Generate stays disabled and the notice shows. No `.env` change.
- **Console errors:** the fixture fails any test with a console error or page error. All 10 temporary tests passed. The probe's direct POST logged one browser "Failed to load resource: 422" line, which comes from my deliberate fetch and not from the app UI.

## Visual verification

Screens touched: Generate (`/dashboard`) and History (`/generations`), signed in as fresh E2E users, at 375, 768 and 1440 px, in both the Chromium desktop and the Pixel 7 mobile projects. Screenshots are full-page PNGs under `.../PE-011/shots/qa/` (52 files, named `<project>-<width>-<state>.png`). Measurements are in `.../shots/qa/measurements.jsonl`.

**Horizontal scroll** (`document.documentElement.scrollWidth − clientWidth`): 0 px in every one of the 50 measured states across both projects and all three widths. Each state was measured on Generate (defaults, AC3 Lighthouse, over-limit, bad URL, server probe, not-configured) and History (empty, several, just submitted).

**Generate, measured:**
- Defaults: Generate disabled, empty prompt.
- AC3 Lighthouse (High, Landscape, one URL, one blank row): Generate enabled, headline `$0.3478 – $0.4572` at all widths.
- Over limit (4,001): counter turns red, "Pixelforge accepts at most 4,000 characters. Shorten your prompt by 1." shown inline, Generate disabled.
- Bad URL (`ftp://`): error "Enter an http:// or https:// image URL." appears under the field once it blurs, Generate disabled. It does not show while the field still has focus (see Notes).
- Not-configured (simulated): notice "Image generation opens soon. You can already see what an image would cost.", Generate disabled.

**History, measured:**
- Empty (new user): "No generations yet." with the create link, at all widths. Nav `aria-current="page"`.
- Several (5 generations, one per state): Succeeded, Failed (`[blocked]`), Failed (`[fail]`), Processing and Queued all render at 375 and 768. Ranges read `Est. $0.3478 – $0.4572`, `Est. $0.0582 – $0.0863` and `Est. $0.0402 – $0.0480`. Meta reads `High · 1536×1024 · 1 reference`, `Low · 1024×1536 · 2 references` and `Medium · 1024×1024 · No references`.
- Just submitted (1440): one item, Queued badge, "Just submitted" tag and a left accent bar; the highlight appears only on the redirect.

**Screens inspected by eye:** desktop 1440 Generate defaults, AC3, over-limit, server probe; desktop 1440 History just submitted; desktop 1440 not-configured; mobile 375 Generate bad URL; mobile 375 History several; desktop 768 History several. The rest were checked by measurement only.

**Against the original Generate screenshot** (`scratchpad/user-generate-page.png`):
- Improved: the breakdown is now one line per row with right-aligned amounts at 1440, where the original stacked the Output and Reasoning amounts under their values. The sidebar is wider, so the labels and values sit on one line. The pricing copy is a compact bulleted list, not a paragraph run. The header has a "New image" title with a subtitle. The current nav item has a visible pill (Generate on the Generate page, History on History).
- Still off: at 375 px the breakdown values drop under their labels, so the Reasoning range "1,000–8,000 estimated" wraps to two lines. The Generate button is right-aligned and not full width on desktop. The URL error line appears only after blur, while the prompt counter error shows as you type, so the two field errors behave differently.

**Persistence (database):** verified in the `generations` and `credit_ledger_entries` tables, not only on screen. For each final-run user, see Data below.

## Data created by the pass

Baseline measured after `make e2e`, before this pass: 161 users, 70 generations, 192 ledger rows.

Current, after all runs: 196 users, 100 generations, 274 ledger rows. Net added by the QA pass: **35 users, 30 generations, 82 ledger rows**. The three partial runs (each stopped by a harness fix, not an app failure) account for part of the count. Nothing was deleted, as the ledger is append-only.

Final run (10 users, one per test per project):

| Test | Users | Generations | Expected | Result |
| --- | --- | --- | --- | --- |
| Generate states + server probe | 2 | 0 | 0 | Pass |
| Not-configured | 2 | 0 | 0 | Pass |
| History empty | 2 | 0 | 0 | Pass |
| Double-click | 2 | 1 each | 1 each | Pass |
| History several | 2 | 5 each (2 Lighthouse, `[fail]`, `[blocked]`, Low/Portrait 2-ref) | 5 each | Pass |

Holds: one per generation (12 in the final run). Lighthouse holds are −457,150,000 n$ (4 rows). `A [blocked] castle` holds −86,287,500 n$ and `[fail]` holds −86,325,000 n$, both Failed and released.

## Notes (non-blocking)

- **Field error timing.** The URL error appears on blur, while the prompt counter error appears while typing. Worth aligning in a follow-up. Not an AC.
- **One unexplained screenshot.** In the spec's server-probe step, the reference row showed `style.png` instead of the `ftp://` value I had typed. A separate probe that repeated the same steps kept the `ftp://` value, sent no request and showed the error. I did not isolate the cause, and the bypass is not a user path. Follow up if it shows up in normal use.
- **Not driven in the browser:** the throttle message (covered by `CreateGenerationThrottleTest`), pagination beyond 20 items, and the two-line clamp with expand on a long prompt (covered by feature and JS tests).
- **No code changed.** The temporary specs were deleted, and the working tree is clean apart from the two QA files this step commits.
