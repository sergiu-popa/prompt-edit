# Plan — PE-011-submit-generation-and-history

## Approach
First, three polish commits, as the user asked on 2026-11-19. The breakdown keeps one row per line in a wider desktop sidebar. The panel's sections and pricing copy get tighter. Pages get a shared header and a visible current nav item.

Next, History is built on factory data. An owner-scoped, newest-first, indexed query is paginated 20 per page through an explicit `GenerationResource` and rendered as items with a status badge, the `money.ts` range and a relative time.

Last, `POST /generations` validates through `CreateGenerationRequest`, with the prompt kept exactly as typed. It is throttled per user with an inline message and refused while Pixelforge isn't configured. It then calls `CreateGenerationAction`, which now creates one generation per form submission token, and redirects to History with the new id flashed, so that generation is highlighted once.

## Tasks
Each task is one TDD commit. Write the failing test first, then the code, and get `make check` green before committing. Also run `make e2e` for tasks 1–3, 9 and 15–19, because they touch a page, the layout or a spec. Tasks 1–3 use the `tailwindcss-development` skill.

Tasks 1–3 are the polish and can be reviewed on their own. Tasks 4–9 are History on factory data. Tasks 10–20 are the submit flow. The cut point is after task 9 (see Size).

- [ ] 1. Add `components/ui/Badge.vue` and use it for the breakdown's exact/estimated flags. Widen the desktop sidebar to 28rem, and switch the breakdown to its table layout from the `@sm` container size. Let the empty-prompt message wrap inside its cell. Test-first: `Badge.test.ts`, plus a desktop row check in `generate.spec.ts`. The existing 375 px card-bounds check stays. Commit: `[PE-011] Keep each estimate line on one row at desktop width`
- [ ] 2. Split the estimate panel into headline, breakdown, totals and a muted pricing footer. Set the copy as a compact list (see Story clarifications), and make MoneyAmount's underline subtle at rest and stronger on hover and focus. Update `COPY` in `EstimatePanel.test.ts` first. Commit: `[PE-011] Separate the estimate panel's sections and tighten its copy`
- [ ] 3. Add `components/ui/PageHeader.vue` and move "New image" into it above the two columns. Space the form sections one step apart, and give the current nav item a visible pill. Test-first: `PageHeader.test.ts`. Commit: `[PE-011] Add a page header and show the current nav item`
- [ ] 4. Add the `(user_id, created_at, id)` index migration (via `make:migration`) and `GenerationQueryBuilder` (`ownedBy`, `newestFirst`), wired into `Generation`. Test-first: `GenerationQueryBuilderTest`, plus an index row in `GenerationTest`. Commit: `[PE-011] Index and query a user's generations newest first`
- [ ] 5. Add `GenerationResource`, `ShowGenerationHistoryController`, the `generations.index` route, a typed `Generations/Index.vue` stub (Head "History", PageHeader "History"), the `Generation` TS type and `types/pagination.ts`. Test-first: `GenerationHistoryPageTest` (AC 11's History half, AC 12, AC 16). Commit: `[PE-011] Serve the History page with the user's generations`
- [ ] 6. Add `lib/relativeTime.ts`, test-first in `relativeTime.test.ts`. Commit: `[PE-011] Format relative and exact timestamps`
- [ ] 7. Add `components/history/HistoryItem.vue`, adding the `info` and `danger` tones to Badge. Test-first: `HistoryItem.test.ts` (AC 13). Commit: `[PE-011] Show a generation as a History item`
- [ ] 8. Build `Generations/Index.vue` from HistoryItem, `components/history/HistoryPagination.vue` and the empty state. Test-first: `Index.test.ts` (AC 12's empty state, AC 15's empty state, pagination). Commit: `[PE-011] List generations on History with an empty state and pages`
- [ ] 9. Add `paths.generations` and the **History** nav item. Add `registerNewUser` to `tests/e2e/support/auth.ts` and `tests/e2e/history.spec.ts` (empty state, current nav item, no horizontal scroll). Add one Tab to `auth.spec.ts`'s keyboard test. Test-first: `AppLayout.test.ts`. Commit: `[PE-011] Add History to the app nav`
- [ ] 10. Add the `submission_token` migration and the token on `NewGenerationData` and `Generation`. Switch `CreateGenerationAction` to `createOrFirst` on `(user_id, submission_token)`. Test-first: new `CreateGenerationActionTest` rows. Commit: `[PE-011] Create one generation per form submission`
- [ ] 11. Add `CreateGenerationActionConcurrencyTest` and `tests/scripts/create-generation.php`. No production code change is expected. Commit: `[PE-011] Prove concurrent submissions of one form create one generation`
- [ ] 12. Add `CreateGenerationRequest` (task 13 adds the text rules), `CreateGenerationController` and the `generations.store` route. Exempt `prompt` from `TrimStrings`, flash the highlight and redirect. Add the `SubmitsGenerations` test trait, and reword `lib/referenceImages.ts`'s server-rule docblock. Test-first: `CreateGenerationValidationTest` (AC 1–3, AC 5's nothing-created) and `CreateGenerationTest` (AC 4, 6, 7, 8, 11). Commit: `[PE-011] Accept generation submissions on POST /generations`
- [ ] 13. Add `StorableTextRule` for the prompt and each reference, and the Unicode whitespace-only prompt rule. Test-first: the deferred rows in `CreateGenerationValidationTest`. Commit: `[PE-011] Reject NUL bytes, invalid UTF-8 and blank prompts inline`
- [ ] 14. Add `config/generations.php` (with its empty name in `.env.example`), the `generation-submissions` rate limiter and its route middleware. Test-first: `CreateGenerationThrottleTest` (AC 10). Commit: `[PE-011] Throttle generation submissions per user`
- [ ] 15. Wire the Generate page's submit. It uses `useForm`, a per-mount `submission_token`, a payload with blank rows dropped, `lib/generationForm.ts`'s `canSubmitGeneration`, the busy label, and `components/generation/SubmitGenerationArea.vue` after the panel. Test-first: `generationForm.test.ts`, and replace `Create.test.ts`'s "never submits" case (AC 3's payload, AC 8's token, AC 9). Commit: `[PE-011] Submit the Generate form with a disabled and busy button`
- [ ] 16. Extract `components/ui/FieldError.vue` from Field. Add server errors to PromptField, RadioGroup and ReferenceImagesField, using `lib/referenceImages.ts`'s `submittedRowIndexes` and `referenceRowErrors`, and the form-level alert. Test-first: component and `Create.test.ts` rows (AC 5, AC 10's message). Commit: `[PE-011] Show server errors inline on the Generate page`
- [ ] 17. Add `isConfigured()` to the Pixelforge contract and both drivers, the request's refusal, and the Generate page's `can_submit` prop, notice and disabled button. Test-first: driver rows, `CreateGenerationTest`, `GenerationFormPageTest`, `BootsWithoutPixelforgeTest` and `Create.test.ts`. Commit: `[PE-011] Keep Generate closed until Pixelforge is configured`
- [ ] 18. Type the flash data, and highlight the flashed generation on History with a "Just submitted" tag. Test-first: `Index.test.ts` and `HistoryItem.test.ts` rows (AC 14). Commit: `[PE-011] Highlight the just-submitted generation once`
- [ ] 19. Add `tests/e2e/submit.spec.ts` for AC 17–19 and the reload row. Commit: `[PE-011] Cover submitting and History end to end`
- [ ] 20. Add D-37 to D-40 to `requirements.md` §5. Commit: `[PE-011] Record the submit and History decisions`

## Story clarifications
- **PR size.** The story asks for a split. The project rule wins: one PR, with the cut point after task 9 (see Size).
- **Whitespace (AC 7, D-37).** The prompt is kept exactly as typed everywhere: in the counter, the estimate, the stored row, the hold and the Pixelforge request. It is exempt from `TrimStrings`, and PE-005 already counts it untrimmed, so Lighthouse with 4 leading spaces is 49 code points and holds −457,156,250 n$.
  - A whitespace-only prompt is rejected on both sides with JS `trim()`'s set: ASCII whitespace, the Unicode `Z` categories and U+FEFF. Laravel's `required` covers ASCII, and task 13 adds the rest.
  - Reference URLs are trimmed on both sides: the page sends `countedReferenceImages()`, and `TrimStrings` still applies to them.
- **URL shape (AC 3).** The server applies `url:http,https` and `max:2048`, and the client keeps `isHttpImageUrl`. Both accept `https://example.com/style.png`, `http://x.test/a` and `HTTPS://EXAMPLE.COM/A.PNG`. Both reject `example.com/a.png`, `ftp://example.com/a.png`, `javascript:alert(1)`, `https://` and `https://exa mple.com`.
  - The server is the authority. A URL that only the client accepts (a raw space in the path) gets that row's field error, never a different price.
- **Double submit (AC 8, D-38).** `crypto.randomUUID()` runs once per mount of the Generate page.
  - A validation, throttle or not-configured response keeps the page mounted (Inertia's POST default `preserveState: true`), so the token stays. That is safe, because nothing was created.
  - Going Back from History remounts the page, so a new submit gets a new token. A replay redirects to History and highlights the first generation.
  - A replay with different fields still returns the first generation: the token names the form submission, and the first request wins.
- **Button (AC 9).** "Generate" is disabled when any of these holds:
  - the prompt is blank by the rule above, or longer than 4,000 code points;
  - the prompt holds a lone surrogate: `\p{Cs}` under the `u` flag, because `isWellFormed` is ES2024 and the tsconfig lib is ES2022;
  - there are more than 4 counted references, or a counted row fails `isHttpImageUrl`;
  - the estimate is unavailable (`null`), because the user must see a price first;
  - `can_submit` is false.

  In flight, it shows a spinner and "Generating…".
- **Layout.** The submit area (notice, form-level alert, button) comes after the panel in the DOM and uses `form="…"`. On mobile it follows the estimate. From `lg` it sits under the fields, in the left column. Enter in a URL row now submits, through the same checks.
- **Server messages.**

  | Error | Message |
  | --- | --- |
  | Prompt is required or blank | "Enter a prompt." |
  | Prompt is too long | "The prompt must be 4,000 characters or fewer." |
  | Unstorable text (prompt or reference) | ":attribute contains characters that can't be sent." |
  | Size or quality | "Choose a size." / "Choose a quality." |
  | A reference is empty or has a bad shape | "Enter an http:// or https:// image URL." (the client's hint) |
  | A reference is too long | "Use a URL of 2,048 characters or fewer." |
  | More than 4 references | "Add up to 4 reference images." |
  | Bad `submission_token` | "Reload the page and try again." |

  `reference_images.*`'s attribute is "Reference image :position". The PromptField's own over-limit error wins over the server's. A field's server error clears as soon as that field changes.
- **Form-level errors.** The key is `submission` (throttle and not-configured), plus `submission_token`. They aren't form fields, so the page reads them from `usePage().props.errors` and shows them in a `role="alert"` line beside the button.
  - Throttle: "You're submitting too quickly. Try again in 42 s.", with the seconds taken from `Retry-After`.
  - Closed: "Image generation isn't available yet."
- **Closed notice (D-40).** "Image generation opens soon. You can already see what an image would cost." It shows above the disabled button.
- **Highlight (AC 14).** `Inertia::flash('highlighted_generation_id', …)` is never saved in history state, so a reload or Back never shows it again. The highlighted item gets an accent bar, a tint and a visible "Just submitted" tag, so the highlight is not shown by colour alone.
- **History item (AC 13).**
  - The prompt is clamped with `line-clamp-2`, and its `title` holds the full prompt for hover. Over 100 code points, or with a line break, a "Show full prompt" / "Show less" toggle (`aria-expanded`) removes the clamp and keeps line breaks.
  - The meta line is `describeImage()` · "1 reference" / "N references" / "No references".
  - Badges: Queued (pending and queued) is neutral, Processing is info, Succeeded is success and Failed is danger. The label is the server's `status_label`.
  - The estimate is "Est. " plus AmountRange.
  - The time is a `<time datetime>` that shows the relative time, with the exact time in its `title`.
- **Relative time.** Under 1 min it reads "just now", then "N min ago" under 1 h, "N h ago" under 24 h and "N d ago" under 7 days. After that it shows the medium date ("Nov 12, 2026"). A future instant reads "just now". The exact time uses `dateStyle: 'medium'` and `timeStyle: 'long'`, in the viewer's zone, or in the zone passed in by tests.
- **History page.**
  - The header reads "History", with "Your generations, newest first." and a **New image** link (Button `as` Link to Generate).
  - Empty (total 0): "No generations yet." and "Write a prompt and see the price before you pay." with **Create your first image →**.
  - Past the last page: "Nothing on this page." with a link to page 1.
  - Pagination reads "Previous", "Page 1 of 3" and "Next", and is hidden on a single page.
- **Generate header.** It reads "New image", with "Describe the image, choose a size and quality, and see the price before you generate."
- **Pricing copy (AC 6 of PE-005, every point kept).** Seven short list items:
  - "You pay Pixelforge's cost + 25%."
  - "Output, reference images and the markup are exact. Prompt text and reasoning are estimates until the image finishes."
  - "“Typically” assumes average prompt-text and reasoning tokens."
  - "The actual cost is recorded when the image finishes, from the tokens Pixelforge used."
  - "Failed generations are free."
  - "Prices are Pixelforge's published rates."
  - "Amounts are rounded to 4 decimals; hover, focus or tap one for the exact value."

  The ceiling-word ban still applies.

## Data model
| Migration | Change |
| --- | --- |
| `add_history_index_to_generations_table` (task 4) | Composite index `(user_id, created_at, id)`. It backs `ownedBy` + `newestFirst` and the user foreign key, which Postgres doesn't index on its own. `id` breaks ties, because `timestampsTz` stores whole seconds. |
| `add_submission_token_to_generations_table` (task 10) | `uuid('submission_token')->nullable()` and a unique `(user_id, submission_token)`. It is null for rows not created from the form (factories, scripts); Postgres keeps NULLs distinct, so those never collide. |

## Backend
All classes are `final` and declare `strict_types`.

| Class / file | Location | Role |
| --- | --- | --- |
| `GenerationQueryBuilder` | `app/Domain/Generation/QueryBuilders/` | `@extends Builder<Generation>`. `ownedBy(User)` filters by `user_id`. `newestFirst()` orders by `created_at` desc, then `id` desc. Wired with `#[UseEloquentBuilder]` on `Generation`, with a `query()` docblock for Larastan. Consumer: `ShowGenerationHistoryController`. |
| `Generation` | `app/Domain/Generation/Models/` | Adds `submission_token` to `#[Fillable]` and `@property ?string`. |
| `NewGenerationData` | `app/Domain/Generation/DataTransferObjects/` | Adds a last `?string $submissionToken = null`: the form submission that sent it, if any. Existing callers don't change. |
| `CreateGenerationAction` | `app/Domain/Generation/Actions/` | With a token, it uses `createOrFirst(['user_id', 'submission_token'], rest)` inside its transaction, where Laravel takes a savepoint, so a conflict never aborts the transaction. The hold and `SubmitGenerationJob` run only when `wasRecentlyCreated`. A replay returns the stored row unchanged. Without a token, it uses a plain `create()`. The docblock cites D-38. |
| `StorableTextRule` | `app/Domain/Generation/Rules/` | `ValidationRule`. It fails for a non-UTF-8 string (`mb_check_encoding`) or one holding U+0000. The domain's `InvalidGenerationRequestException` stays as the last line. |
| `CreateGenerationRequest` | `app/Http/Requests/Generation/` | Its rules, all with `bail`, are listed below the table. `after(PixelforgeClient)` (task 17) adds the `submission` error when `! isConfigured()`. `toNewGenerationData()` builds the DTO from `validated()` only, so `estimate`, `cost`, `user_id`, `metadata` and the like never pass (AC 4). The request builds the DTO, so the domain never imports HTTP. |
| `CreateGenerationController` | `app/Http/Controllers/Generation/` | Invokable: the request, then the action with `$request->user()`, then `Inertia::flash('highlighted_generation_id', $id)`, then `to_route('generations.index')`. A replay ends the same way. Its `RATE_LIMITER = 'generation-submissions'` names the limiter for the route and the provider. |
| `ShowGenerationHistoryController` | `app/Http/Controllers/Generation/` | Invokable. Renders `Generations/Index` with `generations`: `GenerationResource::collection(Generation::query()->ownedBy($user)->newestFirst()->paginate(PER_PAGE))`, where `PER_PAGE = 20`. Paginating in the controller follows guidelines §5. Inertia resolves the collection to `{data, links, meta}`. |
| `GenerationResource` | `app/Http/Resources/` | It sends these fields and nothing else:<br>• `id`<br>• `prompt`<br>• `size`<br>• `quality`<br>• `reference_image_count` (the count of the stored list)<br>• `status`<br>• `status_label` (`GenerationStatus::label()`)<br>• `user_estimate_nanodollars` `{min, max}` as ints<br>• `created_at` (ISO-8601 UTC, `Z`)<br>There is no provider cost, charge, margin, URLs or tokens (AC 16). It reads no relations, so there is no N+1. |
| `ShowGenerationFormController` | `app/Http/Controllers/Generation/` | Task 17 injects `PixelforgeClient` and adds `can_submit` (`isConfigured()`). |
| `PixelforgeClient::isConfigured()` | `app/Support/Pixelforge/Contracts/` | Task 17. Whether `createGeneration` can send at all. `HttpPixelforgeClient`: a non-empty key, which its own guard now uses too. `FakePixelforgeClient`: a non-empty webhook secret, which its guard uses too. Consumers: `ShowGenerationFormController` and `CreateGenerationRequest::after()`. |
| `AppServiceProvider` | `app/Providers/` | Task 14, in `boot()`: `RateLimiter::for(CreateGenerationController::RATE_LIMITER)` is `Limit::perMinute(config)` by user id. Its `response()` is a `back()->withErrors(['submission' => …])` with the seconds from the `Retry-After` header (D-39). |
| `config/generations.php` | `config/` | `submissions_per_minute`: `GENERATION_SUBMISSIONS_PER_MINUTE` read with the `filter_var` min-1 idiom, default 10. The name goes in `.env.example`, empty. |
| `bootstrap/app.php` | | `$middleware->trimStrings(except: ['prompt'])`, with a comment citing D-37. |
| `routes/web.php` | | Inside `auth`: `GET /generations` is `generations.index`. `POST /generations` is `generations.store`, with `throttle:` plus the limiter name. |

`CreateGenerationRequest` rules:
- `prompt`: `required`, `string`, `StorableTextRule` (task 13), not whitespace-only by JS `trim()`'s set (task 13), and `max:` `GenerationInputLimits::MAX_PROMPT_CHARACTERS`. `max` counts code points with `mb_strlen`.
- `size` and `quality`: `required`, plus `Rule::enum` of `ImageSize` / `ImageQuality`.
- `reference_images`: `sometimes`, `array`, `list`, and `max:` `MAX_REFERENCE_IMAGES`.
- `reference_images.*`: `required`, `string`, `StorableTextRule` (task 13), `max:2048` and `url:http,https`.
- `submission_token`: `required` and `uuid`.

## Frontend
`<script setup lang="ts">` only. Primitives in `ui/` import nothing from Inertia.

| File | Role |
| --- | --- |
| `components/ui/Badge.vue` | Inline-flex, `text-xs` with a fixed line height, `rounded-md`, an inset ring and `align-baseline`, so every badge has one size and sits on the text's baseline. The `tone` prop is `neutral`, `success` or `warning` (task 1), plus `info` and `danger` (task 7), and it renders `data-tone`. |
| `components/estimate/EstimateBreakdown.vue` | Task 1: flags become Badges and the container breakpoint goes from `@lg` to `@sm` (24rem). The 28rem panel's content box is about 24.9rem, so desktop is one table row per line, while a 375 px phone (about 19.4rem) still stacks. The cells share one line height. A long tokens cell may wrap its badge, and Output's detail stays on its own line. |
| `components/estimate/EstimatePanel.vue` | Task 2: four sections with one spacing step and dividers. The copy is a `text-xs` disc list in a `bg-zinc-50` footer band, still always visible. |
| `components/ui/MoneyAmount.vue` | Task 2: the dotted decoration is lighter at rest and darker on hover and `focus-visible`. Behaviour, roles and `aria-describedby` are unchanged. |
| `components/ui/PageHeader.vue` | Props `title` and optional `description`, and an `actions` slot. It renders the h1. Consumers: Generate (task 3) and History (task 5). |
| `pages/Generations/Create.vue` | Task 1: grid `lg:grid-cols-[minmax(0,1fr)_28rem]`. Task 3: PageHeader, and the form sections `gap-8`. Task 15: `useForm<GenerationFormInput>` (with no remember key), a `submissionToken` and `submit()`, which returns early when not submittable or already processing. `transform()` sends `prompt`, `size`, `quality`, `countedReferenceImages()` and `submission_token`. On error it focuses the first invalid field. Task 16: errors go to each field. Task 17: the `canSubmit` prop. |
| `components/generation/SubmitGenerationArea.vue` | Task 15. Props: `formId`, `disabled`, `busy`, and `error` (form-level), plus `unavailable` from task 17. The submit Button is full width on mobile and right-aligned from `sm`. |
| `lib/generationForm.ts` | Task 15. `canSubmitGeneration(input, limits, estimateAvailable)` applies the Button rules in Story clarifications. Consumer: Create. |
| `lib/referenceImages.ts` | Task 16 adds `submittedRowIndexes(rows)`, the row index of each counted row, captured at submit. It also adds `referenceRowErrors(indexes, errors)`, which maps `reference_images.N` to the row it came from. The docblock (task 12) names the server rule. |
| `components/ui/FieldError.vue` | Task 16. The Field error markup (icon and message, with an `id`). Consumers: Field, RadioGroup and ReferenceImagesField. |
| `PromptField` / `RadioGroup` / `ReferenceImagesField` | Task 16. Prompt takes `serverError`. RadioGroup takes `error`, linked by the fieldset's `aria-describedby`. References take a list-level `error` and `rowErrors`. A row error replaces that row's hint, with `aria-invalid` set. |
| `types/generation.ts` | Task 5: `GenerationStatus` and `Generation` (the resource's fields). Task 15: `GenerationSubmission` (`GenerationFormInput` with the filtered list and `submission_token`). |
| `types/pagination.ts` | Task 5: `Paginated<T>` = `{data: T[], links: PaginationLinks, meta: PaginationMeta}`, as Laravel's paginated resource response shapes it. |
| `types/inertia.d.ts` | Task 18: `flashDataType` `{highlighted_generation_id?: string}`. |
| `lib/relativeTime.ts` | Task 6: `formatRelativeTime(iso, now)` and `formatExactTimestamp(iso, timeZone?)`, with the rules in Story clarifications. Consumer: HistoryItem. |
| `components/history/HistoryItem.vue` | Task 7: props `generation` and, from task 18, `highlighted`. Desktop is a badge column, then the prompt, meta and "Est." range, with the time on the right. Mobile stacks the badge and time over the prompt. |
| `components/history/HistoryPagination.vue` | Task 8: props `meta` and `links`. Inertia `Link`s for Previous and Next, and "Page x of y" in between. |
| `pages/Generations/Index.vue` | Task 5 stub, task 8 full: PageHeader, then the `<ul>` of items, the empty state or the past-the-end line, then pagination. Task 18 reads `usePage().flash.highlighted_generation_id`. |
| `layouts/AppLayout.vue` + `lib/paths.ts` | Task 3: the pill (`aria-[current=page]` background) on the existing nav. Task 9: `generations: '/generations'` and the **History** item. |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-37 | The prompt is exempt from `TrimStrings`. The page, the estimate, the stored row, the hold and the Pixelforge request all use it exactly as typed. A whitespace-only prompt, by JS `trim()`'s set (ASCII whitespace, the Unicode `Z` categories and U+FEFF), is rejected on both sides. Reference URLs are trimmed on both sides | Laravel's `Str::trim` strips more than JS `trim()` (zero-width and other invisible characters), so trimming on both sides would count two different strings. Kept spaces cost a token or two at most, and the price shown is the price held. Whitespace around a URL never changes the price, which counts references. |
| D-38 | Double submit: the Generate page makes a UUID `submission_token` when it mounts and sends it with the form. `CreateGenerationAction` creates with `createOrFirst` on a unique `(user_id, submission_token)`. A replay returns the first generation with no second hold or job, and lands on History like the first | The database decides, so it holds for a double click, a resent request, a lost response and two concurrent requests. A new visit to Generate mounts a new token, so a deliberate repeat is a new generation. Scoping the token by user means one user can never collide with another's. The ULID stays server-made, because it is Pixelforge's idempotency key (D-05). |
| D-39 | Submissions are throttled per user by a named rate limiter: 10 a minute by default, `GENERATION_SUBMISSIONS_PER_MINUTE`. Over the limit, its response is a redirect back with a form-level `submission` error that gives the seconds from `Retry-After` | Inertia shows a bare 429 as an error modal. A validation-style error keeps the user on Generate with their input and an inline message. It also caps how fast one account can spend before PE-017's balances. |
| D-40 | Generate stays closed until Pixelforge is configured. Each driver reports `isConfigured()`: a key for `http`, a webhook secret for `fake`. Until then the page shows a notice and a disabled button, and a POST is refused with a form-level error before anything is written | Production has no key until PE-015. A generation accepted then could never be submitted, and it would sit pending with its hold until PE-014. |

## Tests
`tests/Concerns/SubmitsGenerations.php` (task 12) is used by the validation, create and throttle tests.
- It fakes `SubmitGenerationJob`, because the sync queue would run it inline (PE-009).
- `submitGeneration(array $overrides = [], ?User $as = null)` posts Lighthouse with a fresh UUID.
- `lighthouseFields()` returns the Lighthouse form fields.
- `assertNothingWasCreated()` checks that there are no generations, no ledger rows and no pushed job.
- From task 17 it uses `InteractsWithPixelforgeApi`, so the sentinel key makes Pixelforge configured by default.

The integration `support/inertia.ts` page gains `flash` (`givenFlash()`), and `givenVisitsFailWith` also writes `page.props.errors`.

| Test | Location | Covers |
| --- | --- | --- |
| `Badge.test.ts`, `PageHeader.test.ts`, `FieldError` cases in `Field.test.ts` | `tests/js/integration/components/ui/` | Slot text, `data-tone` and one size class for every tone. The h1, the description and the actions slot. Field still links its error by `aria-describedby`. |
| `EstimatePanel.test.ts` (existing) | `tests/js/integration/components/estimate/` | The new `COPY` strings, and the ban list still applies. All other rows are unchanged. |
| `generate.spec.ts` (existing) | `tests/e2e/` | Desktop: in the token rows, the label cell and the cost cell have equal tops (one table row). The mobile and 375 px checks are unchanged. |
| `GenerationQueryBuilderTest` | `tests/Unit/Domain/Generation/QueryBuilders/` | `ownedBy` excludes other users. `newestFirst` orders by `created_at` desc, with `id` desc breaking a same-second tie, which the feature test can't pin cheaply. |
| `GenerationTest` (existing) | `tests/Unit/Domain/Generation/Models/` | `Schema::getIndexes` has `(user_id, created_at, id)`. From task 10 it also has the unique `(user_id, submission_token)`. |
| `GenerationHistoryPageTest` | `tests/Feature/Generation/` | AC 11: a guest gets `/login`. AC 12 and 16 are the rows below. |
| `relativeTime.test.ts` | `tests/js/unit/lib/` | Each threshold and its edge (59 s, 60 s, 59 min, 23 h, 6 d, 7 d), the future, and the exact time in UTC. |
| `HistoryItem.test.ts` | `tests/js/integration/components/history/` | AC 13: each status gives its label and tone, pending reads Queued, and the references read 0, 1 and 2. "High · 1536×1024" and "Est. $0.3478 – $0.4572" with exact tips. The clamp, `title`, the toggle only for long prompts, and `aria-expanded`. Relative and `title` times under fake timers. Task 18 adds the highlighted tag and its absence. |
| `Index.test.ts` | `tests/js/integration/pages/Generations/` | Items in the order given. The empty state with its link to `/dashboard`. Past the end. Pagination shows "Page 2 of 3" with both links, and is hidden for one page. Task 18: only the flashed id is highlighted, and nothing is highlighted without a flash. |
| `AppLayout.test.ts` (existing) | `tests/js/integration/layouts/` | History in the nav, current on `/generations` and `/generations?page=2`, and not on `/dashboard`. |
| `history.spec.ts` | `tests/e2e/` | A new user sees the empty state, follows "Create your first image" to Generate, finds History current, and has no horizontal scroll in either project. |
| `auth.spec.ts` (existing) | `tests/e2e/` | The keyboard test tabs Generate, then History, then Sign out. |
| `CreateGenerationActionTest` (existing) | `tests/Unit/Domain/Generation/Actions/` | Task 10. One token twice gives one row, one hold and one pushed job, and the same id back. Another user's same token gives a second row. A null token twice gives two rows. A replay with other fields returns the first row unchanged. |
| `CreateGenerationActionConcurrencyTest` | same | Task 11. The parent inserts a token's row in an open transaction, and a child (`tests/scripts/create-generation.php`) runs the action with that token and waits on the lock. After the parent commits, the child returns the parent's id, and there is one generation and one hold. Uses `WaitsForDatabaseLocks` and `DatabaseTruncation`. |
| `CreateGenerationValidationTest` | `tests/Feature/Generation/` | AC 1–3 and 5: the matrix below. Every rejected row redirects back with the error on the named key and `assertNothingWasCreated()`. |
| `CreateGenerationTest` | `tests/Feature/Generation/` | The rows below. |
| `CreateGenerationThrottleTest` | `tests/Feature/Generation/` | AC 10. With frozen time, 10 posts with fresh tokens give 10 rows. The 11th, 18 s in, redirects back to Generate with `submission` = "…Try again in 42 s." and leaves 10 rows. After 61 s a post works again. A limit of 2 refuses the 3rd. Users have separate buckets. |
| `GenerationFormPageTest` (existing) | `tests/Feature/Generation/` | Task 17: `can_submit` is true with the sentinel key and false with none. The top-level keys become `auth`, `errors`, `pricing`, `limits` and `can_submit`. |
| `BootsWithoutPixelforgeTest` (existing) | `tests/Feature/Production/` | Task 17: with no `PIXELFORGE_*`, Generate has `can_submit` false, and a POST creates nothing. |
| `HttpPixelforgeClientTest`, `FakePixelforgeDriverTest` (existing) | `tests/Feature/Pixelforge/…` | Task 17: `isConfigured()` is true or false for a key or no key, and for a secret or no secret. |
| `generationForm.test.ts` | `tests/js/unit/lib/` | Task 15: each disabling rule on its own, 4,000 versus 4,001 code points, NBSP-only, U+3000-only and FEFF-only prompts, a lone `\uD800`, a paired emoji passes, a null estimate. |
| `referenceImages.test.ts` (existing) | `tests/js/unit/lib/` | Task 16: rows `['a', '', 'b']` give indexes `[0, 2]`, and `reference_images.1` maps to row 2. |
| `Create.test.ts` (existing) | `tests/js/integration/pages/Generations/` | Task 15:<br>• The payload is exactly the five keys, and one URL row plus one blank row sends 1 trimmed reference (AC 3).<br>• Two mounts send different tokens, and resubmitting after an error reuses the token (AC 8).<br>• Each disabled case from AC 9.<br>• While pending: "Generating…", disabled, and one request.<br>• The submit area follows the aside.<br>Task 16: errors appear under the prompt, the size group, the list and row 2 (from `reference_images.1` with a blank row 1), and in the form-level alert. The input is kept, focus moves to the first invalid field, and an error clears when its field changes. Task 17: `can_submit` false shows the notice and disables the button. |
| `PromptField.test.ts`, `RadioGroup.test.ts`, `ReferenceImagesField.test.ts` (existing) | `tests/js/integration/components/…` | Task 16: the server error and its `aria-describedby`. The client over-limit error wins over the server's. A row error replaces the hint. |
| `submit.spec.ts` | `tests/e2e/` | The rows below. Each test registers its own user, and status is read by reloading until the badge appears, with no fixed sleeps. |

`GenerationHistoryPageTest` rows:
- The component is `Generations/Index`, and the only top-level props are `auth`, `errors` and `generations`.
- A user's rows come newest first. An item's keys are exactly the resource's fields, with Lighthouse's ints and `status_label` "Queued" for both `pending` and `queued`.
- 21 rows give 20 on page 1 and 1 on page 2.
- With no rows of their own, a user gets `total` 0, even when another user has rows. That other user's prompt appears nowhere in the page JSON.
- `expectsDatabaseQueryCount` gives the same count for 1 row and for 20.
- A plain GET has no flash.

`CreateGenerationValidationTest` matrix:
- **Prompt:** 3,998 × `a` + 2 × 🎨 passes. 3,999 × `a` + 2 × 🎨 fails on `prompt`. Missing, `''`, `'   '` and `"\n\t"` fail. `prompt[]` (array) fails.
- **Size and quality:** `512x512` fails on `size`, and `ultra` fails on `quality`.
- **References:** absent or `[]` passes, and duplicates pass and are billed 2 × 765 (the stored max shows it). A 5th entry fails on `reference_images`. An empty entry, `ftp://…`, `javascript:alert(1)`, `example.com/a.png` and `https://` fail on `reference_images.N`. A URL of 2,048 characters passes and one of 2,049 fails. A non-list (`reference_images[5]`) and a nested array fail.
- **Token:** a missing or non-UUID `submission_token` fails.
- **Task 13:** a NUL in the prompt (start, middle, end), a NUL inside a URL, form-encoded invalid UTF-8 (`"\xFF"`) in the prompt and in a URL, and NBSP-only, U+3000-only and FEFF-only prompts each give a field error, never a 500.
- **Task 13:** a raw JSON body with an unpaired `"\ud800"` doesn't decode, so it gets required-field errors and creates nothing.

`CreateGenerationTest` rows:
- **AC 6:** Lighthouse creates one pending row for the user with the estimate 347,750,000 / 402,450,000 / 457,150,000, a hold of −457,150,000, one pushed `SubmitGenerationJob`, a redirect to `/generations` and the flash `highlighted_generation_id` = its id.
- **AC 7:** the Unicode Lighthouse stores max 457,156,250. Four leading spaces store the prompt byte-identical, with a max and hold of 457,156,250.
- **AC 4:** `estimate`, `cost`, `user_id` (another user), `metadata`, `status` and `provider_id` are ignored: the owner, the estimate and the columns are the server's.
- **AC 8:** the same token twice gives one row, one hold and one job, and both responses redirect to History. A new token gives a second row.
- **AC 11:** a guest is redirected to `/login` and nothing is created.
- **Task 17:** with Pixelforge not configured, the response redirects back with `submission` and nothing is created.

`submit.spec.ts` rows:

| AC | Steps | Expect |
| --- | --- | --- |
| 17 | Register. Fill Lighthouse, choose High and Landscape, add `https://example.com/style.png` and a blank row. | Headline `$0.3478 – $0.4572`. |
| 17 | Generate. | URL `/generations`. The item tagged "Just submitted" shows `Est. $0.3478 – $0.4572`, "1 reference", and Queued, Processing or Succeeded. |
| 14 | Reload. | No "Just submitted". |
| 18 | Register. Submit `A [blocked] castle` (Medium, Square). | `Est. $0.0582 – $0.0863`, then Failed after reloads. |
| 19 | Register. Fill a prompt and double-click Generate. | History has exactly one item. |
| 8 | Go Back, fill the same prompt and Generate. | Two items. |

| AC | Proven by |
| --- | --- |
| 1–3 | `CreateGenerationValidationTest`, `generationForm.test.ts`, `Create.test.ts` |
| 4 | `CreateGenerationTest` |
| 5 | `CreateGenerationValidationTest` (nothing created), `Create.test.ts` and the field component tests |
| 6–7 | `CreateGenerationTest`, `Create.test.ts` (payload), E2E AC 17 |
| 8 | `CreateGenerationTest`, `CreateGenerationActionTest`, `CreateGenerationActionConcurrencyTest`, `Create.test.ts`, E2E |
| 9 | `generationForm.test.ts`, `Create.test.ts` |
| 10 | `CreateGenerationThrottleTest`, `Create.test.ts` |
| 11 | `CreateGenerationTest`, `GenerationHistoryPageTest` |
| 12 | `GenerationHistoryPageTest`, `GenerationQueryBuilderTest`, `Index.test.ts` |
| 13 | `HistoryItem.test.ts`, `relativeTime.test.ts`, `GenerationHistoryPageTest` |
| 14 | `CreateGenerationTest` (flash), `GenerationHistoryPageTest` (none on GET), `Index.test.ts`, E2E reload |
| 15 | `Index.test.ts`, `AppLayout.test.ts`, `history.spec.ts` |
| 16 | `GenerationHistoryPageTest`, vue-tsc over `types/generation.ts` and `types/pagination.ts` |
| 17–19 | `submit.spec.ts` |
| Closed (orchestrator note) | `GenerationFormPageTest`, `CreateGenerationTest`, `BootsWithoutPixelforgeTest`, the driver tests, `Create.test.ts` |
| Polish 1–6 | `generate.spec.ts` (rows and 375 px bounds), `EstimatePanel.test.ts`, `Badge.test.ts`, `PageHeader.test.ts`, `AppLayout.test.ts`, the MoneyAmount suite (unchanged) |

## Size
The estimate is ≈ 3,000–3,400 changed lines, about 60 % of them tests. The story's own ~700 counted no UI polish, concurrency proof or test traits. This is far above the ≈ 400 budget, and it ships as one PR of self-contained commits, by the project rule.

| Tasks | Content | ≈ Lines |
| --- | --- | --- |
| 1–3 | UI polish | 300 |
| 4–9 | History on factory data (AC 12, 13, 15, 16) | 1,150 |
| 10–14 | Dedupe, concurrency proof, endpoint, text rules, throttle | 1,000 |
| 15–19 | Generate wiring, inline errors, closed state, highlight, E2E | 900 |
| 20 | Decisions | 15 |

**Cut point: after task 9.** PR A is the polish plus History (AC 12, 13, 15, 16), and PR B is tasks 10–20 (AC 1–11, 14, 17–19). A second, smaller cut after task 3 ships the polish alone.

## Risks
- **Tab order (task 9).** History sits between Generate and Sign out, so `auth.spec.ts`'s keyboard test needs one more Tab.
- **PE-005's "never submits" case (task 15)** is replaced, not deleted: a valid form posts, and an invalid one sends nothing. Enter in a URL row now submits through the same guard.
- **The sync queue.** Every POST test must fake `SubmitGenerationJob`. Otherwise it runs inline after commit, `Http::preventStrayRequests` throws, and the test gets a 500.
- **Pages must exist.** `ensure_pages_exist` means task 5 adds `Generations/Index.vue` in the same commit as its controller.
- **Unknown driver.** Resolving `PixelforgeClient` still throws for a misspelled `PIXELFORGE_DRIVER`, so the Generate page answers 500 instead of the notice. That is deliberate, so a config typo is loud. `PixelforgeConfigTest` pins it.
- **The `TrimStrings` exemption** matches every request field named `prompt`. Only this form has one.
- **The breakdown width.** At 28rem the longest lines can wrap a badge under the tokens. The E2E check compares cell tops, which a table row always shares.
- **`crypto.randomUUID`** needs a secure context. Dev (`*.localhost`), E2E (`127.0.0.1`) and production (https) all are, and jsdom provides it.
- **Form-level errors** aren't form fields, so they are read from `page.props.errors`. The integration helper must set them, or the alert test passes vacuously.
- **The query count** includes the auth and session queries, so compare two counts, never a literal.
- **Flash in tests.** Use `hasFlash` / `missingFlash` on `assertInertia`. Flash is persisted only across the redirect.
- **The E2E `[blocked]` row** needs the worker: the local `worker` service, and the CI e2e job's `worker` container (bootstrap PR #6).
- **Production.** After the deploy, `prompt.sergiupopa.dev` shows the closed notice and an empty History. Check both in the post-deploy smoke step (D-19). PE-015's key opens it.
