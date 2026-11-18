# CLAUDE.md — Project Agent Configuration

@~/.claude/docs/laravel-beyond-crud-guidelines.md

## Project
- **Name:** <PROJECT_NAME>
- **Stack:** Laravel 11 + Livewire 4, PHP 8.3+

## Stories
Stories live in `.agent-workflow/stories/<STORY_ID>/` and are committed to this repo.

## Story ID prefix
Use `<PREFIX>` (e.g. `<PREFIX>-001-description`).

## Domain map

| Domain | Purpose |
|---|---|
| `Invoicing` | Invoice creation, editing, sending |
| `Shared` | Cross-domain value objects and enums |

## Project rules
<!-- Anything that overrides or extends the global guidelines. -->

## Commands
```bash
php artisan test
./vendor/bin/pint
```
