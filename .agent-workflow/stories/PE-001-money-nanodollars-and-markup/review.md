# Review — PE-001-money-nanodollars-and-markup

## Round 1

**Verdict: APPROVE**

Scope reviewed: the `[PE-001]` commits `6ffcc27..bf1548d` on `pe-001-money-nanodollars-and-markup` (`git diff origin/main...HEAD`), against `story.md` and `plan.md`.

Checks run on HEAD: `make check` is green. Pint passes on 45 files, Larastan reports no errors, PHPUnit runs 145 tests with 624 assertions, and the JS lint, format, types and Vitest checks pass.

### Summary

The implementation matches the plan class for class: `Nanodollars`, `TokenType`, `TokenRates`, `TokenUsage`, the two Actions and four exceptions under `App\Domain\Billing`.

- **Code style:** every class is `final`, the value objects are `readonly`, and every file declares `strict_types`.
- **Single exit point:** every big number becomes an `int` through `Nanodollars::fromBigInteger()`, which maps brick's overflow to `NanodollarOverflowException`.
- **Float paths:** reading the code and grepping `app/Domain/Billing` found none (no `/` on ints, `round`/`ceil`/`floor`, `number_format`, `(float)` or float literals). The markup ceil is `BigInteger::dividedBy(100, RoundingMode::Ceiling)`.
- **Parsers:** both patterns use `[0-9]` and `\z`, as the plan's pitfalls asked, and boundary rows pin each.
- **Tests:** every AC example (1–17) and every boundary row in the plan is a data-provider row. The reflection guard includes a self-test, so it is known to catch `float`, `mixed` and missing types.
- **Security and performance:** pure PHP with no I/O, so there is no authorization, mass-assignment, SQL or N+1 surface.

### Commit hygiene

The history reads cleanly:

- one bookkeeping commit each for the prompt log, the plan and the task checkboxes;
- the dependency change on its own (`composer.json` +1, lock content-hash only);
- one code commit per primitive, each with its tests.

Subjects follow `[PE-001] Imperative …` and are at most 68 characters, and every commit carries the trailer. After its own task, no commit edits a file from an earlier commit except `Nanodollars.php` in task 3, which is that task's planned scope. Each earlier commit is therefore a strict subset of the green HEAD.

### Warnings

- [x] **PR size breaks the project rule.** `.agent-workflow/CLAUDE.md` § Git hygiene sets about 400 changed lines per PR, excluding lockfiles, and says to split the story if it is larger. This story is much larger:
  - `app/` adds 538 lines and `tests/` adds 831, about 1,370 changed lines;
  - that is nearly double the plan's own ≈650–750 estimate;
  - even the plan's split point at `786a40e` leaves two halves over budget: tasks 1–4 are 545 lines and tasks 5–8 are 824.

  — acknowledged: no engineering action in this pipeline can fix it. Reducing it means dropping AC-required test rows or rewriting history. Most test lines are data-provider rows, and each commit can be reviewed on its own (largest: `fa8b2c6`, 310 lines). **The user must decide this before the PR is opened.** The two options are one PR of 8 small commits, or sequential (not stacked) PRs split at `786a40e`. The PR description must state the size and this choice, and must flag the `brick/math` dependency promotion for approval (CLAUDE.md).

### Suggestions

- [ ] `app/Domain/Billing/Actions/CalculateUserChargeAction.php:23-25`: the docblock lists only `@throws NanodollarOverflowException`, but line 29 also throws `InvalidArgumentException` for a negative cost. Add `@throws InvalidArgumentException` so PE-002/PE-007 callers see both failure modes.
- [ ] `tests/Unit/Domain/Billing/ValueObjects/TokenRatesTest.php:14` and `tests/Unit/Domain/Billing/Actions/CalculateProviderCostActionTest.php:16` both declare the same `PUBLISHED_RATES` constant. That is fine at two files (guidelines §6 extract at 3+). PE-002 introduces the shared pricing JSON fixture, so source these rates from that fixture or a `Tests\Concerns` trait then rather than adding a third copy.
- [ ] `app/Domain/Billing/ValueObjects/Nanodollars.php:26`: `USD_PATTERN` accepts `"-0.0"` and leading zeros (`"007.5"`). They parse to the right amount but do not round-trip to the same string. No AC requires rejecting them, and `/v1/usage` sends canonical 9-decimal strings, so this is optional. If PE-018 wants strict canonical input, tighten the pattern there.
