# Story: PE-005-generate-page-live-estimate

## Title
Generate page with an instant upfront cost estimate

## Status
`planned`

## Description
This is the first product slice. A signed-in user writes a prompt, picks a size and a quality, and optionally adds up to 4 reference image URLs. As they type, the page shows what the generation will cost:

- a min–max range and a typical figure;
- a per-token-type breakdown marking what is exact and what is estimated;
- plain-language pricing copy: Pixelforge's cost + 25%, the actual cost recorded when the image finishes, failed generations free.

The estimate runs entirely in the browser through the PE-002 TS mirror, with no server round trip. Submitting arrives in PE-011. This story also adds `resources/js/lib/money.ts`, the **only** place in the frontend that formats nanodollars.

It serves the required "generate page with upfront estimate" and "pricing", criterion #3 (clear what things cost) and criterion #1 (the numbers shown are the numbers that will be held and charged).

## Acceptance Criteria

**Page and props**

1. The signed-in home is now the Generate page, and guests are redirected to `/login`. Besides the shared `auth` prop, its only props are the pricing table from PE-002 (identical to the fixture's `pricing` block) and the input limits (4,000 prompt code points, 4 reference images). Each prop has a TS type.
2. The defaults are Pixelforge's: empty prompt, **Medium** quality, **Square 1024×1024**, no references. The estimate is visible immediately: **$0.0581 – $0.0863, typically $0.0722** (user 58,125,000 / 72,187,500 / 86,250,000 n$). The prompt-text line reads "0 tokens. Start typing your prompt."

**Live estimate**

3. The estimate updates as inputs change, with no network request. With the 40-code-point prompt `A lighthouse on a cliff at dawn, in oils` (user min / typical / max in n$):
   - Medium, Square, 0 refs → **$0.0582 – $0.0863, typically $0.0723** (58,175,000 / 72,250,000 / 86,325,000)
   - High → **$0.2307 – $0.3401, typically $0.2854** (230,675,000 / 285,375,000 / 340,075,000)
   - High, Landscape 1536×1024 → **$0.3382 – $0.4476, typically $0.3929** (338,175,000 / 392,875,000 / 447,575,000)
   - plus 1 reference → **$0.3477 – $0.4571, typically $0.4024** (347,737,500 / 402,437,500 / 457,137,500; exact max **$0.457137500**)
4. The breakdown for the last case in AC3 shows tokens and Pixelforge cost per token type:

   | Line | Tokens | Cost (min – max) |
   |---|---|---|
   | Prompt text | ≈ 8–12 (estimated) | <$0.0001 – $0.0001 |
   | Reference images | 765 × 1 = 765 (exact) | $0.0077 |
   | Output | 6,450 (exact, High · 1536×1024) | $0.2580 |
   | Reasoning | 1,000–8,000 (estimated) | $0.0125 – $0.1000 |
   | Pixelforge cost | | $0.2782 – $0.3657 |
   | + 25% | | $0.0695 – $0.0914 |
   | You pay | | $0.3477 – $0.4571 |

   - The markup is applied once, to the totals, which is how settlement will charge: 278,190,000 → 347,737,500 and 365,710,000 → 457,137,500 n$.
   - Lines are rounded independently, so they may not add up on screen. The max column's lines read $0.3658 against a $0.3657 total, while the exact values do add up: 60,000 + 7,650,000 + 258,000,000 + 100,000,000 = 365,710,000 n$.
5. Hovering, keyboard-focusing or tapping any displayed amount reveals its exact 9-decimal value, e.g. `$0.457137500`. The value is exposed to assistive technology, so it is not hover-only.
6. The pricing copy is always visible and says, in plain words:
   - you pay Pixelforge's cost + 25%;
   - output, reference images and the markup are exact, while prompt text and reasoning are estimated until the image finishes;
   - "typically" assumes the average prompt-text and reasoning tokens;
   - the actual cost is recorded when the generation finishes, from the tokens Pixelforge actually used;
   - failed generations are free;
   - the estimate uses Pixelforge's published prices.

   The copy never presents the max as a guaranteed ceiling: no "at most", "never more than" or "guaranteed".

**Inputs**

7. **Prompt counter:**
   - It shows code points (`40 / 4,000`), counted the way Pixelforge counts them: typing `👍🏽` adds 2. Help text says some emoji count as more than one character.
   - Typing and pasting are never blocked or truncated, and there is no `maxlength`.
   - Above 4,000 the field and counter turn to an error state: "4,012 / 4,000. Pixelforge accepts at most 4,000 characters. Shorten your prompt by 12."
   - The estimate keeps updating above the limit.
8. Size (Square 1024×1024, Landscape 1536×1024, Portrait 1024×1536) and quality (Low, Medium, High) are keyboard-operable single-choice groups with visible labels.
9. **Reference images:**
   - **Add reference image** adds a URL row, and each row has a remove control. At 4 rows the add button is disabled, with the hint "Up to 4 reference images".
   - Each non-blank row adds exactly 765 input image tokens. Every exact user figure rises by 9,562,500 n$ ($0.009562500), shown as "+$0.0096 each".
   - The displayed 4-decimal figures may move by $0.0095 or $0.0096: the min goes from $0.3382 to $0.3477.
   - Blank or whitespace-only rows are ignored. A non-blank row with an invalid URL still counts, so the figure shows what submitting will cost once it is fixed.
   - A non-blank row that isn't an `http://` or `https://` URL shows "Enter an http:// or https:// image URL." The check is shape only; Pixelforge doesn't download references.
10. If the estimate cannot be computed (for example a malformed pricing prop), the panel shows "We couldn't calculate an estimate right now." instead of numbers. The form keeps working and nothing throws uncaught; the E2E console-error guard stays green.
11. Desktop shows two columns: the form, and an estimate panel that stays in view. Mobile is a single column with the panel after the form. There is no horizontal scroll in the Playwright `mobile` project.

**Money formatting** (`lib/money.ts`)

12. Amounts are formatted to 4 decimals, rounding half away from zero, and also as the exact 9-decimal value:
    - 373,875,000 → `$0.3739` / `$0.373875000`; 467,343,750 → `$0.4673`; 9,562,500 → `$0.0096`;
    - 0 → `$0.0000`; 50,000 → `$0.0001`; 60,000 → `$0.0001`;
    - any non-zero amount below $0.00005 (1 to 49,999 n$, e.g. 40,000) → `<$0.0001`, so a non-zero cost never reads as free;
    - 1,234,567,890,123 → `$1,234.5679` / `$1,234.567890123`;
    - −14,000,000 → `-$0.0140`; −50,000 → `-$0.0001`.
13. `lib/money.ts` throws on non-integers (0.5), NaN, Infinity and integers beyond ±(2⁵³ − 1). No other frontend code formats money. An automated check (a lint rule or equivalent) fails when `toFixed` or `Intl.NumberFormat` currency formatting appears outside `lib/money.ts`.

## Scope

### In Scope
- The authenticated Generate page as the signed-in home, with props (pricing table, input limits) and their TS types, and a "Generate" entry in the app layout nav.
- `resources/js/lib/money.ts` (4-decimal rounding half away from zero, `<$0.0001`, exact 9-decimal, guards), with Vitest unit tests and the automated check from AC13.
- Form components:
  - a prompt with a code-point counter and an over-limit state;
  - size and quality pickers;
  - reference URL rows (add/remove, at most 4, http(s) shape hint).
- Estimate panel:
  - range and typical;
  - a breakdown flagging exact vs estimated;
  - the accessible exact-value reveal;
  - pricing copy;
  - the "estimate unavailable" state.
- A `useEstimate` composable that wires form state to `lib/estimate.ts` and ignores blank reference rows.
- New `ui/` primitives only as needed, e.g. Textarea, SegmentedControl/RadioGroup and Tooltip.
- A PHP feature test, Vitest unit and integration tests, and a Playwright E2E flow (see Technical Notes).

### Out of Scope
- The submit button, server-side validation, creating a generation and History (PE-011).
- Balance, affordability and the "can't afford" state (PE-017).
- Fetching live `/v1/pricing` at runtime; the snapshot drift check is PE-015's.
- Prompt templates, previews of reference URLs, and remembering the last-used settings.

## Technical Notes
- **Sources:**
  - requirements §2.2: tables, code points.
  - §3: display `$0.3739` with the exact 9-decimal value available.
  - §6: rates can change between estimate and creation, so the max is not a guaranteed ceiling and the copy must not promise one. An unreachable reference URL is not an error, so validate shape only.
  - D-03 and D-14: the max shown is what PE-007 will hold.
- **Depends on:**
  - PE-002: `lib/estimate.ts`, the pricing and estimate types, and the pricing table's array form.
  - PE-003: the app layout, primitives and the signed-in home route this page replaces.
- **Conventions (CLAUDE.md):**
  - Money crosses the wire as integer n$, and only `lib/money.ts` formats it.
  - Pages are thin. Feature components go in `components/<feature>/`, stateful logic in `composables/`, pure functions in `lib/`.
  - `ui/` primitives have no Inertia imports.
  - The visual language is Flux-inspired, with no Flux code.
- **Counting:** never use `prompt.length`, and never rely on HTML `maxlength`; both count UTF-16 units. The counter and the estimate must agree with PHP `mb_strlen`, which the PE-002 fixture guarantees.
- **Display rounding:** the 4-decimal figure is a display convenience and must never be mistaken for the charged amount, so the exact value is always reachable (AC5).
- **Numbers:** every figure in AC2–AC4, AC9 and AC12 was recomputed from the pinned snapshot with integer arithmetic. The E2E test asserts the same literals.
- **Required test layers:**
  - PHP feature:
    - `assertInertia` on the component;
    - the pricing prop equals the PE-002 fixture block, and the limits are present;
    - guest redirect;
    - the signed-in home is the Generate page.
  - JS unit (Vitest):
    - every `lib/money.ts` case in AC12–AC13;
    - `useEstimate` reacts to prompt, size, quality and reference changes, ignores blank rows, and exposes the unavailable state for a malformed pricing prop.
  - JS integration (Vitest + Vue Test Utils, Inertia mocked):
    - the counter, including emoji, and the over-limit message;
    - adding and removing references, and the cap at 4;
    - the URL shape hint;
    - figures that change with quality, size and references;
    - the exact value is exposed accessibly;
    - the copy has no ceiling wording;
    - the fallback message when pricing is broken.
  - E2E (Playwright, both projects), signed in as the demo user:
    - the defaults show the AC2 figures;
    - type the AC3 prompt → High → Landscape → add a reference, asserting each AC3 figure and the AC9 "+$0.0096 each";
    - no XHR/fetch request fires while editing;
    - the mobile project has no horizontal scroll.
- **PR size:** estimated 600–700 changed lines: `money.ts`, the composable, five components, three primitives and four test layers. That is clearly over the ~400 budget, so split it before planning into two stories:
  - (a) this story without reference images: `money.ts`, `useEstimate`, the page, prompt/size/quality and the estimate panel (AC1–AC8 and AC10–AC13, with AC3/AC4 ending at High · Landscape);
  - (b) a follow-up story, sequenced before PE-011, that adds the reference-image rows (AC9), the last AC3 step and the references line in AC4.

## Mockups / References

```text
Generate: desktop (40-char prompt, High, Landscape, 1 reference)
┌──────────────────────────────────────────────────────────────────────────────┐
│ PromptEdit   Generate                                Ada Lovelace [Sign out] │
├──────────────────────────────────────────────┬───────────────────────────────┤
│ New image                                    │ Estimated cost                │
│                                              │ $0.3477 – $0.4571             │
│ Prompt                            40 / 4,000 │ typically $0.4024             │
│ ┌──────────────────────────────────────────┐ │                               │
│ │ A lighthouse on a cliff at dawn, in oils │ │ Prompt text  ≈8–12     est.   │
│ │                                          │ │          <$0.0001 – $0.0001   │
│ └──────────────────────────────────────────┘ │ References   765       exact  │
│ Characters are counted like Pixelforge       │                     $0.0077   │
│ counts them; some emoji count as 2 or more.  │ Output       6,450     exact  │
│                                              │                     $0.2580   │
│ Size                                         │ Reasoning  1,000–8,000 est.   │
│ ( Square ) (•Landscape ) ( Portrait )        │          $0.0125 – $0.1000    │
│  1024×1024   1536×1024    1024×1536          │ ───────────────────────────── │
│                                              │ Pixelforge  $0.2782 – $0.3657 │
│ Quality                                      │ + 25%       $0.0695 – $0.0914 │
│ ( Low ) ( Medium ) (•High )                  │ You pay     $0.3477 – $0.4571 │
│                                              │                               │
│ Reference images (optional)           1 of 4 │ You pay Pixelforge's cost     │
│ ┌──────────────────────────────────┐ [×]     │ + 25%. Output, references and │
│ │ https://example.com/style.png    │         │ markup are exact; text and    │
│ └──────────────────────────────────┘         │ reasoning are estimated. The  │
│ [+ Add reference image]   +$0.0096 each      │ actual cost is recorded when  │
│                                              │ it finishes. Failed           │
│                                              │ generations are free.         │
└──────────────────────────────────────────────┴───────────────────────────────┘
 Hover / focus / tap any amount → exact value, e.g. "$0.457137500"

Empty state (defaults: empty prompt, Medium, Square)
│ Estimated cost                │
│ $0.0581 – $0.0863             │
│ typically $0.0722             │
│ Prompt text  0 tokens. Start typing your prompt.

Prompt over the limit
│ Prompt                                      4,012 / 4,000 (red) │
│ ┌──────────────────────────────────────────┐ (red border)      │
│ │ …                                        │                   │
│ └──────────────────────────────────────────┘                   │
│ ⚠ Pixelforge accepts at most 4,000 characters. Shorten your prompt by 12.

Reference images at the cap
│ Reference images (optional)                      4 of 4 │
│ [ https://…/a.png ][×]  [ https://…/b.png ][×]          │
│ [ https://…/c.png ][×]  [ https://…/d.png ][×]          │
│ [+ Add reference image] (disabled)  Up to 4 reference images.

Invalid reference URL (still counted in the estimate)
│ ┌──────────────────────────────┐ [×]
│ │ example.com/cat.png          │
│ └──────────────────────────────┘
│ ⚠ Enter an http:// or https:// image URL.

Estimate unavailable
│ Estimated cost                                  │
│ We couldn't calculate an estimate right now.    │

Mobile (single column, no horizontal scroll)
┌──────────────────────────────┐
│ PromptEdit         [Sign out]│
├──────────────────────────────┤
│ Prompt            40 / 4,000 │
│ [ A lighthouse on a cliff … ]│
│ Size  (Sq) (•Land) (Port)    │
│ Quality (Low) (Med) (•High)  │
│ Reference images     1 of 4  │
│ [ https://…/style.png ] [×]  │
│ [+ Add reference image]      │
│ ──────────────────────────── │
│ Estimated $0.3477 – $0.4571  │
│ typically $0.4024            │
│ breakdown + pricing copy …   │
└──────────────────────────────┘
```

- Pixelforge pricing and token counting: https://pixelforge.promptedit.dev/docs#pricing
- Create-generation parameters (prompt ≤ 4,000 characters, sizes, qualities, ≤ 4 http(s) references billed at 765 tokens each, not downloaded): https://pixelforge.promptedit.dev/docs#create-generation
- requirements.md §2.2, §3, §6; decisions D-03, D-14; PE-002 shared fixture
