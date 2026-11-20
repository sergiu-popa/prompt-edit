# Audit — PE-011-submit-generation-and-history

**Verdict: PASS**

Run on branch `pe-011-submit-generation-and-history` from `08c5480` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (round 2 APPROVE) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-011]` diff (`main..08c5480`, 78 files outside `.agent-workflow`) against `story.md` AC 1–19, `plan.md`, both review rounds, QA and D-37 to D-40. That covers:
- `CreateGenerationRequest`, `StorableTextRule`, `CreateGenerationController`, `CreateGenerationAction` with its token, the two migrations, `GenerationQueryBuilder`, `GenerationResource`, `ShowGenerationHistoryController`, the rate limiter, `config/generations.php`, the `TrimStrings` exemption and `isConfigured()` on both drivers;
- the Generate page, `SubmitGenerationArea`, the field components' server errors, History with its item, pagination and highlight, the nav, the TS types, and the `lib/` helpers;
- every new or changed PHP, Vitest and Playwright test.

I read Inertia 3.11's router to confirm the defaults the design relies on: POST, PUT, PATCH and DELETE visits use `preserveState: true`, so a rejected submit keeps the page mounted with its input and token. Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene).

## What was verified

- **Money and trust.**
  - The estimate is recomputed on the server by `EstimateGenerationCostAction`, from the validated prompt, quality, size and reference count only. `toNewGenerationData()` reads `validated()`, so the client's `estimate`, `cost`, `user_id` and `metadata` never reach the domain. `test_fields_beyond_the_form_are_ignored` pins this.
  - The hold is `user_estimate_max_nanodollars` (D-14), written in the same transaction as the row.
  - Double submit is guarded by the database, not the button. The unique `(user_id, submission_token)` is hit through `createOrFirst` under a savepoint. The hold and the job run only when `wasRecentlyCreated`. `CreateGenerationActionConcurrencyTest` proves it across two real connections. Postgres's `uuid` type compares case-insensitively, so an upper-case replay of the same token still matches.
  - The throttle is a named per-user limiter, 10 a minute by default. Its `response()` redirects back with a form-level `submission` error that takes its seconds from `Retry-After`, so Inertia never shows a 429 modal. It sits inside `auth`, so `->by()` always has a user.
- **Validation.**
  - The prompt is limited to 4,000 code points by `max` (`mb_strlen`), which is how the PHP and TS estimators count it. `StorableTextRule` runs first under `bail`, so `mb_strlen` and the `/u` blank pattern never see invalid UTF-8.
  - NUL and invalid UTF-8, in the prompt or a reference, each give a field error, never a 500. Flashed old input goes to the database session base64-encoded, so those bytes are safe there too.
  - There are at most 4 references, each a `url:http,https` of at most 2,048 characters, and they are never fetched.
  - TrimStrings is applied the same way everywhere. The prompt is exempt (D-37), so the counter, the estimate, the stored row, the hold and the Pixelforge payload all use the same string. References are trimmed on both sides, and their price depends only on how many there are.
  - The blank-prompt set matches JS `trim()`: ASCII whitespace, `\p{Z}` and U+FEFF.
- **Authorization.** History is scoped by `ownedBy($user)` in the query. `test_history_lists_only_the_signed_in_users_generations` and `test_a_user_with_no_generations_sees_none_even_when_another_user_has_some` use two users, and the second one also checks that the other user's prompt is absent from the page JSON. There is no detail route.
- **Not-configured guard (D-40).** `CreateGenerationRequest::after()` adds `submission` when `isConfigured()` is false, so the request fails before the controller and no row, hold or job is written. `BootsWithoutPixelforgeTest::test_a_submission_creates_nothing_and_says_generation_is_not_available` pins it for a production-shaped boot.
- **PE-005 accessibility kept.**
  - MoneyAmount's exact-value button, its `aria-describedby` tooltip and its pointer and focus handling are unchanged. Only the underline classes changed.
  - The estimate's `aria-live="polite"` announcement is still in the headline.
  - Field still wires the hint, aside and error into `aria-describedby` and sets the invalid flag. RadioGroup and the reference list gain `aria-describedby` for their server errors, and a row error replaces that row's hint.
  - The submit area's form-level error is a `role="alert"`, and the button points at both the notice and the error. QA measured no horizontal scroll in 50 states, at 375, 768 and 1440 px, in both projects.
- **Vue layer.** No `any` appears in `resources/js`, `tests/js` or `tests/e2e`. No file in `components/ui/` or `lib/` imports Inertia. History's page is thin. The Generate page wasn't thin; see #1.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The Generate page held the form's stateful logic: `useForm`, the submission token, `isSubmittable`, the submitted-row mapping, the form-level error, four error-clearing watchers and `submit()` with its transform. That is against the project rule "pages: thin, wire props to components; composables: stateful logic". | `resources/js/pages/Generations/Create.vue:29-94` | Warning | Fixed | Moved into `composables/useGenerationForm.ts`, which also takes in the `useEstimate` call. The page passes its props as getters and its focus handler as `onRejected`, then wires the results to its components. Behaviour is unchanged: all of `Create.test.ts`'s submit, payload, token, disabled, busy, server-error and closed-state rows pass without edits, and so does the E2E suite (`f202976`). No separate composable unit test was added, because its whole behaviour is already covered through the page, which owns the template ref and the form element that it needs. |
| 2 | The first and last History items hard-coded `rounded-[11px]` for "the list's `rounded-xl` minus its 1 px border" (review S2). | `resources/js/components/history/HistoryItem.vue:64` | Suggestion | Fixed | They now use `rounded-t-[calc(var(--radius-xl)-1px)]` and `rounded-b-[calc(var(--radius-xl)-1px)]`, which still compute to 11px. The built CSS has both rules, and `--radius-xl` is `.75rem`. The other fix from the review, `overflow-hidden` on the `<ul>`, was not used, because it would clip the last row's MoneyAmount tooltips (`9d01e8d`). |
| 3 | The form-level throttle or closed alert stays until the next submit, even after an edit (review S3). | `resources/js/composables/useGenerationForm.ts` (`formError`) | Suggestion | Skipped | The wait still applies after an edit. Clearing the alert when the `Retry-After` time runs out would need a timer, and the server answers a too-early retry the same way anyway. |
| 4 | The URL shape error appears on blur, while the prompt's over-limit error appears as the user types (QA note). | `resources/js/components/generation/ReferenceImagesField.vue` | Suggestion | Skipped | This is PE-005's deliberate design, written in the component's docblock: the hint waits for blur so that typing `https://` never flashes an error. The over-limit error is a count, so it is correct after every keystroke. |
| 5 | In the 28rem desktop sidebar, the Reasoning row's "estimated" badge wraps under `1,000–8,000`, and at 375 px the breakdown stacks each cost under its tokens (QA "still off"). | `resources/js/components/estimate/EstimateBreakdown.vue:139-145` | Suggestion | Skipped | The plan accepts both under Risks ("The breakdown width"): the row stays one table row, and the label, tokens and cost cells still share their tops, which `generate.spec.ts` checks. Stacking at phone width is what keeps the amounts inside the card. |
| 6 | One QA screenshot of a client-side bypass showed `style.png` in place of the typed `ftp://` value (QA note). | `tests/e2e` (temporary QA spec, deleted) | Suggestion | Skipped | It came from a QA step that removed `disabled` in the DOM, which no user can do. A clean repeat of the same steps kept the value and sent no request, and no real user path reproduces it. |
| 7 | History has no policy. | `app/Http/Controllers/Generation/ShowGenerationHistoryController.php:30` | Suggestion | Skipped | It lists only the signed-in user's own rows, scoped in the query, and has no route that takes an id. A policy would only be needed for a detail route, which is out of scope. |

No Critical issues were found. The one Warning is fixed.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0. Pint passes on 226 files, and Larastan reports no errors. PHPUnit: 1,182 passed (34,185 assertions). ESLint, Prettier and vue-tsc are clean. Vitest: 417 passed in 27 files. |
| E2E, desktop and mobile | `make e2e` | Exit 0. 24 passed and 2 skipped, the designed touch-only and mouse-only rows of `generate.spec.ts`. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-011/audit-check.log` and `.../PE-011/audit-e2e.log`. The baseline before the fixes, in `.../PE-011/audit-baseline.log`, gave the same counts. No real Pixelforge request was made.
