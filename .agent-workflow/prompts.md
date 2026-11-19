# Prompt log

Every prompt given to the AI coding assistant (Claude Code) while building this
project, verbatim, in order. Long pasted documents are replaced by a pointer.
Answers to the assistant's clarifying questions are logged too, since they
steer the work.

---

## 2026-11-18 — Session 1: requirements, stack switch, backlog

**Prompt 1**

> This is the starter Laravel folder for this job:
>
> *[pasted: the PromptEdit "Senior Product Engineer" job posting]*
>
> This is the assessment:
>
> *[pasted: the take-home assessment brief — summarised in `requirements.md` §1; full text at https://pixelforge.promptedit.dev/assessment]*
>
> I added the workflow structures in .agent-workflow dir. Analyse the requirements, update the project after the requirements (Inertia and Vue instead of Livewire) and write the stories.
>
> It's important to keep Git hygiene, meaning that the PRs should be easily reviewable by a human, short commits, TDD approach as much as possible. Also on the frontend side, keep things decoupled and testable: unit, integration and then E2E with Playwright.
>
> Update the README.md after this, and also keep a log of the prompts. Questions?

**Prompt 2** (mid-task, confirming the docs URL the assistant found)

> https://pixelforge.promptedit.dev/docs Yes, that's correct

**Answers to clarifying questions**

| Question | Answer |
|---|---|
| Switch MySQL → Postgres? | Switch to Postgres |
| Auth/UI scaffold? | Minimal hand-built. You can take inspiration from FluxUI components |
| Which stretch goals get stories? | Both |
| Create the public GitHub repo now? | Create public repo now |

**Prompt 3**

> I created an API key for PixelForge. It's in the .env file PIXELFORGE_API_KEY. Use it without exposing it

**Prompt 4**

> Bring down other Docker stacks and give priority to this one to check that container are built correctly and running

**Prompt 5**

> Don't do a story for the project rename and scaffolding, bootstrap, do it here directly. Keep the stories only for building and functionality.

---

## 2026-11-19 — Session 2: story pipeline

**Prompt 6**

> All PRs have been merged. Let's run the pipeline for each story, in order, 1 by 1, auditor included. Once I review and merge a PR, you'll start the next one. Don't forget about GIT hygiene, I need to keep up with the new code.

**Prompt 7** (while PE-003 was in the pipeline)

> What's the next story about? I don't want to deploy on Laravel Cloud, but on my own server at prompt.sergiupopa.dev (check deploy.php on ../yum project)

**Answers to clarifying questions**

| Question | Answer |
|---|---|
| Production database on the droplet? | Install Postgres 17 |
| Queue, cache and sessions in production? | Database driver |
| How do the worker and scheduler run? | systemd + cron |
| Who deploys after each merge? | The assistant runs `dep deploy` |

**Prompt 8** (PE-004, after installing the PHP Postgres driver on the droplet)

> apt install php8.4-pgsql executed. Given me the command for the rest of them

**Prompt 9** (PE-004)

> You can ssh into the droplet with root, so show me commands before you run them

**Prompt 10** (after PE-004 was merged, while PE-005 was in the pipeline)

> Can you run the pipeline in workflow until PE-010 in parallel? We need to finish this today, so as far as you can go, then we switch back to sequential stories

**Answers to clarifying questions** (PE-005 and PE-006 had each failed QA on one bug)

| Question | Answer |
|---|---|
| Run one engineer fix round, then QA again, then the audit, for both? | Fix both |
| When QA fails for the rest of today, run one fix round automatically instead of stopping? | One automatic fix round |
| Make one PR per story the explicit rule, even above the ~400-line budget? | One PR per story |
