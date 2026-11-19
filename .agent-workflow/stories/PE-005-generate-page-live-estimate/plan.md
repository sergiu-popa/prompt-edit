# Plan — PE-005-generate-page-live-estimate

## Approach
The `dashboard` route keeps its URL and name, and a thin invokable controller now renders `Generations/Create` with two props: `PricingTable::toArray()` from the singleton and `GenerationInputLimits::toArray()`. On the client, a `useEstimate` composable feeds the reactive form state (prompt, size, quality, then reference rows) into PE-002's `estimateGenerationCost`, returns `null` when that throws, and drives an estimate panel without sending any request. Every amount goes through `lib/money.ts`, and an ESLint rule keeps it the only money formatter.

## Tasks
Each task is one TDD commit: write the failing test first, then the code, and run `make check` green before committing. Also run `make e2e` for tasks 3, 4, 11, 12, 14 and 15, because they touch a page, the layout or a spec. Tasks 1–12 are the page without reference images. Tasks 13–15 add the reference rows (AC9, the last AC3 step and the AC4 references line), so the PR can still be cut after task 12 (see Size).

- [x] 1. Add `lib/money.ts` (`formatUsd`, `formatUsdExact`), test-first in `tests/js/unit/lib/money.test.ts` for every AC12 and AC13 row plus the money boundary rows below. Commit: `[PE-005] Format nanodollars in lib/money.ts`
- [x] 2. Add the money-formatting lint rule to `eslint.config.js`, test-first in `tests/js/unit/lint/moneyFormatting.test.ts` through ESLint's Node API, and record D-25. Commit: `[PE-005] Lint against money formatting outside lib/money.ts`
- [x] 3. Serve the signed-in home through `ShowGenerationFormController` with the `pricing` and `limits` props. Add `GenerationInputLimits` and `types/generation.ts`. Move `pages/Dashboard.vue` to `pages/Generations/Create.vue` as a typed stub (Head "Generate", h1 "New image"), and update the `Dashboard` component name in `FortifyRoutesTest` and `SignInTest` and the heading in `auth.spec.ts`. Test-first: `GenerationFormPageTest` (AC1). Commit: `[PE-005] Serve the Generate page with pricing and limits props`
- [x] 4. Replace AppLayout's unused `nav` slot with a built-in nav holding a **Generate** link, which has `aria-current="page"` on its own page. Add `url` to the integration `page` stub. Rewrite the nav-slot case in `AppLayout.test.ts`, and add the extra Tab in `auth.spec.ts`'s keyboard test (see Risks). Commit: `[PE-005] Add a Generate entry to the app nav`
- [x] 5. Add `composables/useEstimate.ts`, the `GenerationFormInput` type and the shared `tests/js/support/pricing.ts`. Test-first in `tests/js/unit/composables/useEstimate.test.ts` (AC2, AC3 rows 1–3, AC7's over-limit estimate, AC10's null). Commit: `[PE-005] Compute the live estimate in a useEstimate composable`
- [x] 6. Add `components/ui/Textarea.vue`, test-first in `Textarea.test.ts`. Commit: `[PE-005] Add the Textarea primitive`
- [x] 7. Add `components/ui/RadioGroup.vue`, test-first in `RadioGroup.test.ts` (AC8). Commit: `[PE-005] Add a segmented RadioGroup primitive`
- [x] 8. Add `components/ui/MoneyAmount.vue`, test-first in `MoneyAmount.test.ts` (AC5). Commit: `[PE-005] Add MoneyAmount with an accessible exact value`
- [x] 9. Add `lib/counting.ts`, switch `lib/estimate.ts` to its `countCodePoints`, give `Field` an `aside` slot and build `components/generation/PromptField.vue`. Test-first: `PromptField.test.ts` (AC7) and one `Field.test.ts` case. Commit: `[PE-005] Add the prompt field with a code-point counter`
- [x] 10. Add `lib/imageOptions.ts`, `components/estimate/EstimatePanel.vue` and `EstimateBreakdown.vue`, test-first in `EstimatePanel.test.ts` (AC2's display, the pre-cut AC4 rows, AC6, AC10's message). Commit: `[PE-005] Add the estimate panel with breakdown and pricing copy`
- [x] 11. Build `Generations/Create` from the form state, `useEstimate`, PromptField, two RadioGroups and EstimatePanel in the two-column layout, test-first in `tests/js/integration/pages/Generations/Create.test.ts` (AC2, AC3 rows 1–3, AC8, AC10, AC11). Commit: `[PE-005] Build the Generate page on the live estimate`
- [x] 12. Move `signIn` into `tests/e2e/support/auth.ts`, and add `tests/e2e/generate.spec.ts` for the pre-cut E2E rows below. Commit: `[PE-005] Cover the live estimate end to end`
- [x] 13. Add `lib/referenceImages.ts`, the `reference_images` form field, and `useEstimate`'s reference count and `perReferenceNanodollars`. Test-first: `referenceImages.test.ts` and new `useEstimate.test.ts` rows (AC3 row 4, AC9's counting). Commit: `[PE-005] Count non-blank reference rows in the estimate`
- [x] 14. Add `components/generation/ReferenceImagesField.vue` and the breakdown's references line, and wire both into the page. Test-first: `ReferenceImagesField.test.ts`, plus new `EstimatePanel.test.ts` and `Create.test.ts` rows (AC9's UI, AC4's full table). Commit: `[PE-005] Add reference image rows to the Generate page`
- [x] 15. Extend `generate.spec.ts` with the reference step (AC3 row 4, "+$0.0096 each" and the `$0.457137500` reveal). Commit: `[PE-005] Cover adding a reference image end to end`

### Engineer notes
- **Extra commits.** Four small follow-ups found while checking the page in a browser: breakdown ranges no longer wrap after the dash (the panel is 24rem, not 22rem); the mobile header shows the nav above the user name; Add and Remove keep focus on mousedown, because an invalid row's hint appeared on blur, pushed the button down and swallowed the click; the reference count lines up with its legend.
- **Small deviations.** `components/estimate/AmountRange.vue` renders "min – max" (or one amount) for the panel and the breakdown. The breakdown derives the per-image count from the estimate (`tokens ÷ counted rows`) instead of a `tokensPerReferenceImage` prop, so a malformed pricing prop can't throw in the page template. `lib/counting.ts` got its own unit test. `tests/js/integration/support/text.ts` reads text as rendered (skipping closed tips); the E2E spec uses `useInnerText` for the same reason.
- **Lint test.** The TypeScript project service only parses files tsconfig includes, so `.ts` snippets are linted as the text of `resources/js/lib/pageTitle.ts`; a parse error fails the test instead of passing silently.
- **vue-tsc quirk.** A static `placeholder="https://…"` on a component inside a `v-for` breaks vue-tsc's codegen, so ReferenceImagesField binds it from a constant.

## Story clarifications
- **URL.** The signed-in home stays at `/dashboard` with the name `dashboard`. Fortify's `home`, Laravel's `guest` redirect, the PE-003/PE-004 tests and the production smoke all point there. Renaming it later takes the route, `config/fortify.php` and the test URLs.
- **AC7's message.** The counter shows `4,012 / 4,000` in red, and the Field error under the textarea reads "Pixelforge accepts at most 4,000 characters. Shorten your prompt by 12.". Together they make up the AC's sentence, and the textarea's description reads the counter, the hint and the error. Exactly 4,000 is valid; 4,001 says "by 1".
- **Prompt counting.** The prompt is counted as typed, with no trim, like PE-002. PE-011 chooses a whitespace rule and must apply it here too (its AC7).
- **AC6's ban.** It covers the pricing copy, so the test scopes to the estimate panel. The prompt's over-limit error legitimately says "at most".
- **Copy.** Six plain statements, one per AC6 bullet, plus "Amounts are rounded to 4 decimals. Hover, focus or tap one for the exact value." Shown in every state, including when the estimate is unavailable.
- **References line.** Shown only when at least one non-blank row counts. With none, the breakdown has three token lines.
- **URL hint.** Shown for a non-blank, non-http(s) row while that row isn't focused, so typing `https://` never flashes an error. Shape only: trimmed, parsed by `URL`, protocol `http:` or `https:`.
- **Tiny negatives.** −1 to −49,999 n$ format as `-<$0.0001`, the mirror of AC12's positive rule; −0 formats as `$0.0000`.
- **Unavailable.** `useEstimate` catches whatever `estimateGenerationCost` throws, returns `null` and logs nothing. The server already validated the table at boot, so only a malformed prop in a test reaches this.

## Backend
| Class / file | Location | Role |
| --- | --- | --- |
| `GenerationInputLimits` | `app/Domain/Generation/ValueObjects/` | `final`. Int constants `MAX_PROMPT_CHARACTERS` = 4,000 and `MAX_REFERENCE_IMAGES` = 4 (Pixelforge's create-generation limits), and a static `toArray()` with an array shape: `max_prompt_characters`, `max_reference_images`. PE-011's request rules reuse the constants. Creates the `Generation` domain folder from the domain map. |
| `ShowGenerationFormController` | `app/Http/Controllers/Generation/` | `final`, invokable. It takes the `PricingTable` singleton and renders `Generations/Create` with `pricing` (`toArray()`) and `limits`. No Action or Request, so no name-parity siblings. The name avoids clashing with a future `Create…Action`. |
| `routes/web.php` | | Inside the `auth` group, `Route::get('/dashboard', ShowGenerationFormController::class)->name('dashboard')` replaces the `Route::inertia` line. |

## Frontend
`<script setup lang="ts">` only. Primitives take props, emit events and import nothing from Inertia.

| File | Role |
| --- | --- |
| `lib/money.ts` | The only money formatter. `formatUsd(n)` returns 4 decimals rounded half away from zero, with `<$0.0001` for 0 < \|n\| < 50,000 and thousands grouping. `formatUsdExact(n)` returns 9 decimals. Both use bigint arithmetic only, and both throw on a non-integer, NaN, ±Infinity or anything beyond ±`Number.MAX_SAFE_INTEGER`. |
| `eslint.config.js` | A block for `resources/js/**/*.{ts,vue}` that ignores `resources/js/lib/money.ts`. `no-restricted-syntax` (script) and `vue/no-restricted-syntax` (template expressions) share two selectors: `.toFixed` member access, and any `style: 'currency'` object property (how `Intl.NumberFormat` and `toLocaleString` format currency). The message points to `lib/money.ts`. No new dependency. |
| `lib/counting.ts` | `countCodePoints(text)` (`[...text].length`) is the one counting rule for the counter and `lib/estimate.ts`. `formatCount(n)` gives en-US grouping (`4,012`). |
| `lib/imageOptions.ts` | `SIZE_OPTIONS` (Square / Landscape / Portrait with `1024×1024`-style dimensions), `QUALITY_OPTIONS` (Low / Medium / High), `DEFAULT_SIZE` = `1024x1024` and `DEFAULT_QUALITY` = `medium`. Consumers: the page's pickers and defaults, and the breakdown's Output line ("High · 1536×1024"). |
| `lib/referenceImages.ts` | `countedReferenceImages(rows)`: the non-blank rows, which PE-011 will also submit. `isHttpImageUrl(value)`: the shape check. Consumers: `useEstimate` and `ReferenceImagesField`. |
| `types/generation.ts` | `GenerationInputLimits` (`max_prompt_characters`, `max_reference_images`), the `limits` prop type. `GenerationFormInput` (`prompt`, `size`, `quality`, and from task 13 `reference_images: string[]`), named as PE-011 will submit them. |
| `composables/useEstimate.ts` | `useEstimate(pricing, form)`, where `form` is the reactive `GenerationFormInput`. Returns `estimate`: a computed `GenerationCostEstimate` or `null`. From task 13 it also returns `referenceImageCount` (counted rows) and `perReferenceNanodollars` (the user max with one more counted row, minus the current user max). |
| `components/ui/Textarea.vue` | Like `TextInput`: `defineModel<string>`, an `invalid` prop (`aria-invalid` and a red border), attributes fall through, and never a `maxlength`. |
| `components/ui/RadioGroup.vue` | Generic over `T extends string`. A `<fieldset>` with a visible `<legend>`, then one native radio per option, all sharing a `useId()` name. Each option is wrapped in its label, with an optional detail line, and styled as a segment with checked and focus-visible states. Native radios give Tab and arrow-key operation (AC8). |
| `components/ui/MoneyAmount.vue` | Prop `nanodollars`. A `type="button"` toggletip shows `formatUsd`. A `role="tooltip"` element holds `formatUsdExact` and is linked by `aria-describedby`. It shows on mouse hover, focus and tap, and hides on Escape, blur and pointer leave. When hidden it uses `display: none`, so it never widens the page. Consumers: the panel, the breakdown and "+$0.0096 each". |
| `components/ui/Field.vue` | Gains an optional `aside` slot, right-aligned on the label row (the counter). |
| `components/generation/PromptField.vue` | v-model string and a `maxCharacters` prop. Field "Prompt" with Textarea. The aside shows the `formatCount` counter, red above the max. Hint: "Characters are counted like Pixelforge counts them; some emoji count as more than one character." The over-limit error is as in Story clarifications. It never blocks or truncates input. |
| `components/generation/ReferenceImagesField.vue` | v-model `string[]`, plus `max` and `perReferenceNanodollars` props. A fieldset "Reference images (optional)" with an "N of 4" count. Each row has a URL TextInput labelled "Reference image n", the URL hint and a × remove button labelled "Remove reference image n". **Add reference image** focuses the new row and is disabled at `max`, with the hint "Up to 4 reference images". Below the cap it shows "+[MoneyAmount] each". |
| `components/estimate/EstimatePanel.vue` | Props: `estimate` (or null), `quality`, `size`, and from task 14 `referenceImageCount` and `tokensPerReferenceImage`. Heading "Estimated cost", then the headline range and "typically …" in a polite live region. The breakdown follows, then the pricing copy. A null estimate shows only "We couldn't calculate an estimate right now." and the copy. |
| `components/estimate/EstimateBreakdown.vue` | A table with a row header per line. Prompt text: "≈ min–max", flagged "estimated", or "0 tokens. Start typing your prompt." when the prompt is empty. References (task 14): "765 × n = total", flagged "exact". Output: "count", flagged "exact" with "Quality · W×H". Reasoning: "min–max", flagged "estimated". Then Pixelforge cost, "+ 25%" (user − provider, per bound) and You pay. Equal bounds show one amount, and each line is rounded on its own. |
| `pages/Generations/Create.vue` | Props `pricing: PricingTable` and `limits: GenerationInputLimits`. `reactive` form state with Pixelforge's defaults. A `<form novalidate>` whose submit is prevented (PE-011 wires it), with h1 "New image", PromptField, the Size and Quality RadioGroups and, from task 14, ReferenceImagesField. EstimatePanel sits in an `<aside>`: one column on mobile with the panel after the form, and from `lg` a two-column grid with the panel sticky. |
| `layouts/AppLayout.vue` | The nav holds **Generate** (to `paths.dashboard`), with `aria-current="page"` when `usePage().url`'s path matches. PE-011 adds History to the same list. |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-25 | Only `resources/js/lib/money.ts` formats money in the UI. Amounts show 4 decimals rounded half away from zero, and any non-zero amount under $0.00005 shows `<$0.0001`. The exact 9-decimal value is always reachable by hover, focus or tap. An ESLint rule rejects `toFixed` and currency-style `Intl` formatting anywhere else in `resources/js`. Later PHP displays (PE-016) use the same rule. | Display rounding is a convenience and never the charged amount. A non-zero cost never reads as free. One formatter means one rounding rule. |

## Tests
`tests/js/support/pricing.ts` exports the fixture's `pricing` block typed as `PricingTable`. Its consumers are `useEstimate.test.ts`, `EstimatePanel.test.ts` and `Create.test.ts`. Expected figures are the story's literals. The pre-cut rows were derived from AC3 with integer arithmetic.

| Test | Location | Covers |
| --- | --- | --- |
| `GenerationFormPageTest` | `tests/Feature/Generation/` | AC1. A guest is sent to `/login`. Signed in: component `Generations/Create`, `pricing` identical to `estimatesFixture()['pricing']`, `limits` exactly 4,000 / 4, and the top-level prop keys exactly `auth`, `errors`, `pricing`, `limits`. |
| `FortifyRoutesTest`, `SignInTest` (existing) | `tests/Feature/Auth/` | The component name becomes `Generations/Create`. Sign-in still lands on the signed-in home. |
| `BootsWithoutPixelforgeTest` (existing) | `tests/Feature/Production/` | Unchanged. It already hits `/dashboard` with no `PIXELFORGE_*`. |
| `money.test.ts` | `tests/js/unit/lib/` | AC12–13 rows, plus the boundary rows below. |
| `moneyFormatting.test.ts` | `tests/js/unit/lint/` | `lintText` with a `filePath`. Errors for `toFixed` in a `.ts`, in a `.vue` script and in a `.vue` template, and for `{ style: 'currency' }` passed to `Intl.NumberFormat` and to `toLocaleString`. No error for the same code at `resources/js/lib/money.ts`, or for plain `Intl.NumberFormat('en-US')`. |
| `useEstimate.test.ts` | `tests/js/unit/composables/` | Defaults → AC2 n$. Lighthouse → AC3 row 1, High → row 2, Landscape → row 3. A 4,012-code-point prompt still estimates. A malformed table (a missing `tokens` block; a 1.5 rate) → `null` and no throw. Task 13 adds: one URL → row 4; `''` and `'   '` rows → same as none; `example.com/cat.png` counts; `perReferenceNanodollars` = 9,562,500. |
| `referenceImages.test.ts` | `tests/js/unit/lib/` | The rows below. |
| `Textarea.test.ts`, `RadioGroup.test.ts`, `MoneyAmount.test.ts` | `tests/js/integration/components/ui/` | Textarea: v-model both ways, `invalid`, no `maxlength`, attribute fall-through. RadioGroup: visible legend, labelled radios sharing one name, the model's option checked, a change emits, two groups get distinct names. MoneyAmount: `$0.4571` shown; the description is `$0.457137500`; the tooltip is hidden by default; it shows on mouseenter, focus and click and hides on Escape, blur and mouseleave; 40,000 → `<$0.0001` / `$0.000040000`. |
| `Field.test.ts` (existing) | same | Plus: the aside slot renders on the label row. |
| `PromptField.test.ts` | `tests/js/integration/components/generation/` | AC7: `0 / 4,000`; Lighthouse → `40 / 4,000`; appending `👍🏽` → `42 / 4,000`; the hint. 4,000 is valid and 4,001 says "by 1". `'a' × 4,010 + 👍🏽` → `4,012 / 4,000` (not 4,014), with the error, `aria-invalid` and the whole value kept. No `maxlength`. The description holds the counter, the hint and the error. |
| `EstimatePanel.test.ts` | `tests/js/integration/components/estimate/` | AC2 headline and "0 tokens. Start typing your prompt.". The pre-cut breakdown below, with exact/estimated flags. Each copy statement is present, and the panel text has no "at most", "never more than" or "guaranteed" (case-insensitive). A null estimate → the AC10 message, no `$` amount and the copy still shown. Polite live region. Task 14 adds the full AC4 table. |
| `ReferenceImagesField.test.ts` | `tests/js/integration/components/generation/` | AC9. Add appends and focuses a row. Remove buttons are labelled per row. "1 of 4". At 4 rows: Add disabled, "Up to 4 reference images". "+$0.0096 each" with exact `$0.009562500`. The hint appears after blur for `example.com/cat.png`, linked by `aria-describedby`; there is none for blank, whitespace or `https://` rows. |
| `Create.test.ts` | `tests/js/integration/pages/Generations/` | Defaults: Medium and Square checked, an empty prompt, AC2 figures. Typing, then High, then Landscape → AC3 rows 1–3. Broken pricing → the AC10 message, while the counter and radios still work. A submit is prevented. Task 14 adds: a URL → row 4, a blank row → unchanged, an invalid row → counted, remove → back to row 3. |
| `AppLayout.test.ts` (existing) | `tests/js/integration/layouts/` | The nav-slot case is rewritten: **Generate** in `<nav>`, with `aria-current="page"` on `/dashboard` and not elsewhere. |
| `generate.spec.ts` | `tests/e2e/` | Both projects, as the demo user, in the E2E rows below. |
| `auth.spec.ts` (existing) | `tests/e2e/` | The heading becomes "New image", and the keyboard test tabs past Generate to Sign out. |

Money boundary rows (task 1):

| Input (n$) | `formatUsd` | `formatUsdExact` |
| --- | --- | --- |
| 1 / 49,999 | `<$0.0001` | `$0.000000001` / `$0.000049999` |
| 149,999 / 150,000 | `$0.0001` / `$0.0002` | |
| 999,950,000 | `$1.0000` (carry into dollars) | `$0.999950000` |
| 999,999,999,999 | `$1,000.0000` | `$999.999999999` |
| 9,007,199,254,740,991 | `$9,007,199.2547` | `$9,007,199.254740991` |
| −40,000 / −0 | `-<$0.0001` / `$0.0000` | `-$0.000040000` / `$0.000000000` |
| −14,000,000 | `-$0.0140` | `-$0.014000000` |
| 2⁵³, −2⁵³, 0.5, NaN, ±Infinity | throws | throws |

Reference shape rows (task 13): `https://example.com/style.png`, `http://x.test/a` and `HTTPS://EXAMPLE.COM/A.PNG` pass. Surrounding spaces are trimmed, so `  https://example.com/a.png  ` passes too. `example.com/cat.png`, `ftp://example.com/a.png`, `javascript:alert(1)`, `https://` and `https://exa mple.com` fail. `countedReferenceImages` drops `''`, `'   '` and `'\t'`, and keeps the order of the rest.

Pre-cut breakdown (Lighthouse, High · 1536×1024, no references):

| Line | Tokens | Cost |
| --- | --- | --- |
| Prompt text | ≈ 8–12 (estimated) | <$0.0001 – $0.0001 |
| Output | 6,450 (exact, High · 1536×1024) | $0.2580 |
| Reasoning | 1,000–8,000 (estimated) | $0.0125 – $0.1000 |
| Pixelforge cost | | $0.2705 – $0.3581 (270,540,000 – 358,060,000) |
| + 25% | | $0.0676 – $0.0895 (67,635,000 – 89,515,000) |
| You pay | | $0.3382 – $0.4476 |

E2E rows (`generate.spec.ts`):

| Task | Row | Expect |
| --- | --- | --- |
| 12 | Sign in as the demo user | `/dashboard`, h1 "New image", **Generate** current, "$0.0581 – $0.0863", "typically $0.0722", "0 tokens. Start typing your prompt." |
| 12 | Record `xhr`/`fetch` requests from here on | Checked as empty at the end of the test |
| 12 | Fill the Lighthouse prompt | `40 / 4,000`, AC3 row 1 |
| 12 | Focus Medium, then press ArrowRight | High checked, AC3 row 2 (AC8 by keyboard) |
| 12 | Click Landscape | AC3 row 3 |
| 12 | Focus the max amount | Tooltip `$0.447575000` visible; accessible description equal to it |
| 12 | Layout | Desktop: panel to the right of the form. Mobile: panel below it, no horizontal scroll with the tooltip open. |
| 15 | Add a reference and fill `https://example.com/style.png` | AC3 row 4, "+$0.0096 each". The max reveals `$0.457137500`. Still no `xhr`/`fetch`. |

| AC | Proven by |
| --- | --- |
| 1 | `GenerationFormPageTest`, `SignInTest`, `FortifyRoutesTest`, types used by the page (vue-tsc) |
| 2 | `useEstimate.test.ts`, `EstimatePanel.test.ts`, `Create.test.ts`, E2E |
| 3 | `useEstimate.test.ts`, `Create.test.ts`, E2E (rows 1–3 in task 12, row 4 in task 15) |
| 4 | `EstimatePanel.test.ts` (pre-cut rows, then the full table in task 14) |
| 5 | `MoneyAmount.test.ts`, E2E reveal |
| 6 | `EstimatePanel.test.ts` |
| 7 | `PromptField.test.ts`, `useEstimate.test.ts` |
| 8 | `RadioGroup.test.ts`, `Create.test.ts`, E2E keyboard row |
| 9 | `referenceImages.test.ts`, `useEstimate.test.ts`, `ReferenceImagesField.test.ts`, `Create.test.ts`, E2E task 15 |
| 10 | `useEstimate.test.ts`, `EstimatePanel.test.ts`, `Create.test.ts`; the E2E console guard |
| 11 | E2E layout row |
| 12–13 | `money.test.ts`, `moneyFormatting.test.ts`, `make js-check` in CI |

## Size
Estimate: ≈1,500–1,750 changed lines, about half of them tests. That is well over the ≈400 budget, and as with PE-001 to PE-004 it ships as one PR of self-contained commits, as the user asked.

| Tasks | Content | ≈ Lines |
| --- | --- | --- |
| 1–2 | `money.ts`, the lint rule, their tests, D-25 | 220 |
| 3–4 | Controller, limits, route, types, page rename, nav, test edits | 190 |
| 5–9 | `useEstimate`, three primitives, `PromptField`, `counting.ts`, tests | 530 |
| 10–12 | Panel, breakdown, `imageOptions.ts`, page, integration and E2E | 480 |
| 13–15 | Reference rows (the cut-off part) | 280 |

If a cut is wanted, cut after task 12. PR A is the page without references (AC1–8 and AC10–13, with AC3/AC4 ending at High · Landscape), and PR B is tasks 13–15, sequenced before PE-011.

## Risks
- **Tab order (task 4).** CSS `order` doesn't change tab order. The Generate link sits between the brand and **Sign out** in the DOM, so `auth.spec.ts`'s "Tab from the brand reaches Sign out" breaks unless it tabs past Generate.
- **Implicit submission.** A `<form>` with a single URL input submits on Enter, which would reload the page and send a request. The page prevents submit from task 11 on, and `Create.test.ts` pins it.
- **Template expressions.** Core `no-restricted-syntax` never sees `<template>` code, so the rule needs `vue/no-restricted-syntax` as well. In flat config a later block's `no-restricted-syntax` replaces an earlier one, so keep both selectors in one block. The ESLint Node API loads the TS and Vue parsers slowly, so give that suite a longer timeout if the 5 s default trips.
- **Hidden tooltips.** An `invisible` or `opacity-0` tooltip still takes layout and can cause horizontal scroll on mobile, so hide it with `display: none`. `aria-describedby` still names hidden content, and the E2E reveal row asserts the accessible description in both projects.
- **The ceiling wording test** must read only the panel. The page also contains the prompt error's "at most".
- **The client URL check versus PE-011's server rule.** Both must accept the same shapes. `lib/referenceImages.ts` documents its rule, and PE-011 aligns its validation and test rows with it.
- **`ensure_pages_exist`.** The PHP suite checks that the component file exists, so task 3 must move `Dashboard.vue` in the same commit that changes the render.
- **Production.** `prompt.sergiupopa.dev` is redeployed after merge. The page has no submit, reads only the pinned snapshot, and `BootsWithoutPixelforgeTest` already covers `/dashboard` without `PIXELFORGE_*` (PE-004 AC14). The production smoke doesn't assert the heading.
- **Live region noise.** Only the headline is `aria-live="polite"`, not the breakdown. While typing, the 4-decimal figures rarely change, so announcements stay infrequent.
