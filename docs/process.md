# How this is built

- **Requirements first.** [`requirements.md`](../.agent-workflow/requirements.md) distils the brief and the Pixelforge docs, checked against the live API, into facts, a money model, a robustness matrix and a decisions log.
- **Stories, then an agent pipeline.** Each [story](../.agent-workflow/stories) moves through *Architect* (`plan.md`) → *Engineer* (code and tests) → *Reviewer* (`review.md`) → *QA* (`qa.md`, including a browser pass), with an optional *Auditor* for risky stories. The whole decision trail is committed next to the code. Project rules for the agents are in [`.agent-workflow/CLAUDE.md`](../.agent-workflow/CLAUDE.md).
- **Bootstrap work has no stories.** Tooling such as the stack switch, CI and the test harness goes on `bootstrap/*` branches with `[bootstrap]` commits.
- **Git hygiene.** One story = one PR of about 400 changed lines, short `[PE-NNN]` commits that each carry their own tests, and TDD throughout.
- **AI tools.** Built with Claude Code. Every prompt is logged verbatim in [`.agent-workflow/prompts.md`](../.agent-workflow/prompts.md).
