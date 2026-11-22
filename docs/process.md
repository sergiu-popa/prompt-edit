# How this is built

- **Requirements first.** [`requirements.md`](../.agent-workflow/requirements.md) distils the brief and the Pixelforge docs, checked against the live API, into facts, a money model, a robustness matrix and a decisions log.
- **Stories, then an agent pipeline.** Each [story](../.agent-workflow/stories) moves through *Architect* (`plan.md`) → *Engineer* (code and tests) → *Reviewer* (`review.md`) → *QA* (`qa.md`, including a browser pass), with an optional *Auditor* for risky stories. The whole decision trail is committed next to the code. Project rules for the agents are in [`.agent-workflow/CLAUDE.md`](../.agent-workflow/CLAUDE.md).
- **Bootstrap work has no stories.** Tooling such as the stack switch, CI and the test harness goes on `bootstrap/*` branches with `[bootstrap]` commits.
- **Git hygiene.** One story = one PR of about 400 changed lines, short `[PE-NNN]` commits that each carry their own tests, and TDD throughout.
- **AI tools.** Built with Claude Code. The steering prompts up to PE-015 are logged verbatim in [`.agent-workflow/prompts.md`](../.agent-workflow/prompts.md); the log was closed after that story.

## Time spent

- **Agent pipelines: about 24 h of agent time** for the 18 stories (1,437 agent-minutes, from the Pipeline table in each story's PR). By step: engineer 9.9 h, architect 5.2 h, QA 2.9 h (on Haiku), audit 2.7 h, code review 1.8 h, and fix rounds 1.3 h. That covers 31 code-review rounds and 24 QA runs.
- **Wall clock: 35.7 h**, from the first PE-001 commit (2026-11-19 10:23 CET) to the PE-018 merge (2026-11-20 22:03 CET). It includes an 8.8 h overnight wait, so about 27 h were active. Two parallel runs saved at least 3 h: PE-005 to PE-010 as a chain on 2026-11-19, and PE-016 to PE-018 in isolated lanes on 2026-11-20.
- **The owner: about 3 to 8 h hands-on.** That is prompting 1 to 2 h, reviewing PRs 1.5 to 4.5 h, and merging and setup 0.5 to 1.5 h. It came from 37 prompts, 4 question dialogs and 27 merges. Grouping those events gives 5.4 h of active periods. This is an estimate from timestamps: reading a PR away from the keyboard leaves none.
- **Quota:** the owner estimates 30 to 40% of a weekly Claude quota, on Opus 5.5 at xhigh effort with Ultracode.
