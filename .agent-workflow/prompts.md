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
