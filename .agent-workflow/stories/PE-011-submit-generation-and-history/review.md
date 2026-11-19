# Review — PE-011-submit-generation-and-history

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 23 `[PE-011]` commits `f847487..f0a393b` on `pe-011-submit-generation-and-history` (`origin/main..HEAD`), against `story.md` and `plan.md`. That is the prompt log, the plan, 20 task commits and the bookkeeping. All 20 plan tasks are checked.

Checks run on HEAD:
- `make check` exits 0. Pint (226 files) and Larastan are clean. PHPUnit runs 1,182 tests (34,185 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 417 tests in 27 files, all passing.
- `make e2e` exits 0, with 24 passed and 2 skipped. The skips are the existing touch-only and mouse-only rows of `generate.spec.ts`, one per project, as designed.

There is no Critical finding. One Warning must change, and three optional Suggestions follow.

### Summary

The implementation follows the plan class for class. Where it deviates, it says so in the plan's Story clarifications.

- **History (AC 12, 13, 15, 16):**
  - `GenerationQueryBuilder::ownedBy()->newestFirst()` is served by the new `(user_id, created_at, id)` index. That index also covers the user foreign key, which PE-006 left unindexed. The `id` tie-break is sound, because the ULID grows with time.
  - `GenerationResource` lists its nine fields one by one: money is integer n$, there is no provider cost, charge, URL or token usage, and it reads no relation. `test_the_query_count_does_not_grow_with_the_page` compares 1 row with 20 rather than a literal, as the plan's risk note asks.
  - `Paginated<T>` matches Laravel's `PaginatedResourceResponse` shape. A page past the end gets its own "Nothing on this page" state instead of the empty state.
  - HistoryItem covers each item field of AC 13: the two-line clamp with `title` and the expand toggle, the meta line, the badge tones (pending reads Queued), the `money.ts` range, and `<time datetime>` with the exact time in its `title`.
- **Submit (AC 1–11):**
  - `CreateGenerationRequest` matches the plan's rules exactly: `bail` everywhere, `StorableTextRule` before `max`, so `mb_strlen` never sees invalid UTF-8, and `not_regex` with `/u` for JS `trim()`'s whitespace set. `toNewGenerationData()` builds from `validated()` only, so AC 4 holds by construction. `test_fields_beyond_the_form_are_ignored` pins it.
  - The `TrimStrings` exemption for `prompt` (D-37) gives one string everywhere. The 4-leading-spaces row pins 457,156,250 for the stored max and the hold. `ConvertEmptyStringsToNull` still applies, so `''` fails `required`.
  - Double submit (D-38): `createOrFirst` on the unique `(user_id, submission_token)` runs inside the action's transaction. Laravel takes a savepoint there, so a conflict never aborts the outer transaction. The hold and the job run only when `wasRecentlyCreated`. `CreateGenerationActionConcurrencyTest` proves the blocking case across two real connections and truncates its committed rows afterwards. The token is made once per mount, and `useForm` has no remember key, so Back gives a new token, as the E2E row confirms.
  - The throttle (D-39) is a named limiter keyed by user id. Its `response()` redirects back with a form-level `submission` error that takes its seconds from `Retry-After`. The frozen-time test pins "Try again in 42 s." at 18 s in and recovery at 61 s.
  - The not-configured guard (D-40) sits in the request's `after()`, so nothing is written. `isConfigured()` now backs each driver's own guard too, and `@phpstan-assert-if-true` keeps Larastan's narrowing of `$apiKey`.
  - Client: `canSubmitGeneration` mirrors the server's rules, including the lone-surrogate check. The payload is exactly the five keys, with `countedReferenceImages()`. `submittedRowIndexes` and `referenceRowErrors` map `reference_images.N` back to its row across blank rows.
- **Highlight (AC 14):** `Inertia::flash` is never kept in history state. `hasFlash` / `missingFlash` pin it on the server, and the E2E reload row confirms it in a real browser. A replay highlights the first generation.
- **E2E (AC 17–19):** each test registers its own user, and status is read by reloading with `toPass`, with no fixed sleeps.

Security: every route is inside `auth`, History is owner-scoped in the query, and there is no mass-assignment path, because the request builds the DTO and `user_id` comes from `$request->user()`. Prompts render through Vue interpolation only, so there is no XSS.

The constant-field check: `GenerationResource` returns no field that is constant under `ownedBy`, because `user_id` is not sent.

### Commit hygiene

- Each task commit carries its code with its tests. The prompt log (`f847487`), the plan (`7384180`), the decisions (`a3bab5d`) and the bookkeeping (`f0a393b`) have their own commits.
- All subjects start with `[PE-011]`, are imperative and are at most 72 characters. Every message ends with the trailer.
- The polish (tasks 1–3) comes first and can be reviewed alone, and the plan names the cut point after task 9, as the project rule asks.

### Warnings

- [ ] **W1. `isConfigured()` was inserted between an existing docblock and its method, in both drivers.**
  - `app/Support/Pixelforge/Drivers/FakePixelforgeClient.php:132-141`: `refusal()`'s docblock ("The docs-shaped error for a refused create.") now sits above `isConfigured()`'s own docblock, so it documents nothing, and `refusal()` at :143 has lost its doc.
  - `app/Support/Pixelforge/Drivers/HttpPixelforgeClient.php:85-96`: `generationFrom()`'s `@throws AmbiguousPixelforgeOutcomeException` (:86) is now a dead docblock above `isConfigured()`'s. `generationFrom()` at :98 no longer declares the exception it throws, so IDEs and Larastan's throws analysis lose it.
  - Why it matters: PHP attaches only the last docblock before a declaration. The first block in each pair is dead, and it reads as if it described `isConfigured()`. These are the provider integration's own contracts.
  - Fix: in each file, move `isConfigured()` with its docblock above the orphaned block, for example right after `createGeneration()`'s closing brace, so `refusal()` and `generationFrom()` get their docblocks back. This is a doc-only change, so `make check` is enough. Commit it on its own, for example `[PE-011] Keep the drivers' docblocks on their own methods`.

### Suggestions

Optional; none blocks QA.

- **S1.** `resources/js/components/generation/SubmitGenerationArea.vue:7-12`: the docblock was extended in place, and line 9 runs to about 130 characters while the rest wrap at about 90. Rewrap it when W1's commit is made, or leave it.
- **S2.** `resources/js/components/history/HistoryItem.vue:64`: `group-first:rounded-t-[11px]` / `group-last:rounded-b-[11px]` hard-code "the list's `rounded-xl` minus its 1 px border". `rounded-t-[calc(var(--radius-xl)-1px)]` would say that and follow a theme change. Alternatively, `overflow-hidden` on the `<ul>` in `Index.vue` would make the item's rounding unnecessary.
- **S3.** `resources/js/pages/Generations/Create.vue:53`: the form-level alert (throttle or closed) stays until the next submit, even after the user edits a field, while field errors clear on change. That is reasonable, because the wait still applies. Clearing `submission` only when the throttle's seconds have passed would need a timer, and isn't worth it now. It is recorded here so QA doesn't file it as a bug.
