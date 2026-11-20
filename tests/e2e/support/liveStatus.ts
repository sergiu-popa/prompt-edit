import { readFileSync } from 'node:fs';

import type { Locator, Page } from '@playwright/test';
import { expect } from '@playwright/test';

/*
 * The fake Pixelforge's timings, as the dev `.env` leaves them (config/pixelforge.php
 * defaults); `make e2e` runs against that `.env`, so non-default PIXELFORGE_FAKE_*
 * values there make these timeouts wrong.
 */

/** `fake.queue_seconds`: how long an accepted generation stays queued. */
const FAKE_QUEUE_MS = 1_000;

/** `fake.processing_seconds`: how long it then stays processing. */
const FAKE_PROCESSING_MS = 3_000;

/** `fake.slow_processing_seconds`: how long a `[slow]` one stays processing. */
const FAKE_SLOW_PROCESSING_MS = 10_000;

/** `fake.expire_ttl_seconds`: how long an `[expire]` image URL works after completion. */
export const FAKE_EXPIRE_TTL_MS = 5_000;

/** `--sleep=3` in .docker/supervisor/conf.d/worker.conf: an idle worker's wait before it looks for a job. */
const WORKER_SLEEP_MS = 3_000;

/** useLiveUpdates' LIVE_UPDATE_INTERVAL_MS: History's wait between polls. */
const POLL_INTERVAL_MS = 3_000;

/** One queue worker serves every parallel Playwright worker, so jobs can wait their turn. */
const MARGIN = 2;

/**
 * Three jobs run one after another (the submit, then the fake's two advances), each
 * maybe a worker sleep late; then History sees the result within one poll.
 */
function untilTerminal(processingMs: number): number {
    return MARGIN * (3 * WORKER_SLEEP_MS + FAKE_QUEUE_MS + processingMs + POLL_INTERVAL_MS);
}

/** From submit to a terminal badge on History. */
export const TERMINAL_TIMEOUT_MS = untilTerminal(FAKE_PROCESSING_MS);

/** From submit to a `[slow]` generation's terminal badge. */
export const SLOW_TERMINAL_TIMEOUT_MS = untilTerminal(FAKE_SLOW_PROCESSING_MS);

/** From submit to a `[slow]` generation's Processing badge. */
export const PROCESSING_TIMEOUT_MS = untilTerminal(0);

/** A local failure needs only the submit job, then one poll. */
export const LOCAL_FAILURE_TIMEOUT_MS = MARGIN * (WORKER_SLEEP_MS + POLL_INTERVAL_MS);

/** From Succeeded on screen to "Image expired": the TTL, plus a second for the clock's tick. */
export const EXPIRY_TIMEOUT_MS = MARGIN * (FAKE_EXPIRE_TTL_MS + 1_000);

/**
 * A `PIXELFORGE_*` seconds value as the app reads it: the dev containers' env, which
 * compose also gives Playwright (D-48), else `.env.example`'s line, which CI's image is
 * built from, beside this checkout.
 */
function pixelforgeSeconds(name: string): number {
    const fromTemplate = new RegExp(`^${name}=(\\d+)$`, 'm').exec(readFileSync('.env.example', 'utf8'))?.[1];
    const seconds = Number(process.env[name] || fromTemplate);

    if (!Number.isInteger(seconds) || seconds < 1) {
        throw new Error(`${name} is neither in the env nor in .env.example.`);
    }

    return seconds;
}

/** `submit.overloaded_deadline_seconds`: how long an `[overloaded]` prompt is retried before it fails free. */
const OVERLOADED_DEADLINE_MS = pixelforgeSeconds('PIXELFORGE_OVERLOADED_DEADLINE') * 1_000;

/** The fake's `Retry-After` for an `[overloaded]` create (FakePixelforgeClient). */
const OVERLOADED_RETRY_AFTER_MS = 2_000;

/** The submit job's first attempt, then one poll, shows the retry note. */
export const RETRY_NOTE_TIMEOUT_MS = MARGIN * (WORKER_SLEEP_MS + POLL_INTERVAL_MS);

/**
 * From the retry note to the free failure: the deadline, a last wait of up to one
 * `Retry-After`, two worker sleeps around the released job, then one poll.
 */
export const OVERLOADED_FAILURE_TIMEOUT_MS =
    MARGIN * (OVERLOADED_DEADLINE_MS + OVERLOADED_RETRY_AFTER_MS + 2 * WORKER_SLEEP_MS + POLL_INTERVAL_MS);

/** `reconcile.every_seconds`: the scheduler's wait between reconciler runs. */
const RECONCILE_PERIOD_MS = pixelforgeSeconds('PIXELFORGE_RECONCILE_EVERY') * 1_000;

/** `reconcile.queued_after_seconds`: how long a queued generation goes without news before a poll. */
const RECONCILE_QUEUED_AFTER_MS = pixelforgeSeconds('PIXELFORGE_RECONCILE_QUEUED_AFTER') * 1_000;

/** `reconcile.processing_after_seconds`: likewise for a processing one. */
const RECONCILE_PROCESSING_AFTER_MS = pixelforgeSeconds('PIXELFORGE_RECONCILE_PROCESSING_AFTER') * 1_000;

/**
 * From submit to a terminal badge when no webhook arrives (AC 22): the three jobs as in
 * untilTerminal(), then the queued threshold, and the processing threshold too if the poll
 * lands mid-processing, each noticed up to one reconciler period late; then one poll.
 */
export const POLLED_TERMINAL_TIMEOUT_MS =
    MARGIN *
    (3 * WORKER_SLEEP_MS +
        FAKE_QUEUE_MS +
        FAKE_PROCESSING_MS +
        RECONCILE_QUEUED_AFTER_MS +
        RECONCILE_PROCESSING_AFTER_MS +
        2 * RECONCILE_PERIOD_MS +
        POLL_INTERVAL_MS);

/** `usage.cache_seconds`: how long the report reuses a `/v1/usage` answer per range. */
const USAGE_CACHE_MS = pixelforgeSeconds('PIXELFORGE_USAGE_CACHE_SECONDS') * 1_000;

/**
 * From a generation's Succeeded badge to the report's today showing Match: a parallel
 * `[no-webhooks]` spec's generation may finish at the fake long before the reconciler
 * records it, so up to a polled terminal, then a cached answer's lifetime, each with margin.
 */
export const USAGE_MATCH_TIMEOUT_MS = POLLED_TERMINAL_TIMEOUT_MS + MARGIN * USAGE_CACHE_MS;

export interface PromptOptions {
    quality?: 'Low' | 'Medium' | 'High';
    size?: 'Square' | 'Landscape' | 'Portrait';
    referenceImage?: string;
}

/**
 * Submits a prompt from Generate, as a user would, and waits for History.
 */
export async function submitPrompt(page: Page, prompt: string, options: PromptOptions = {}): Promise<void> {
    await page.getByLabel('Prompt').fill(prompt);

    if (options.quality) {
        await page.locator('label', { hasText: options.quality }).click();
    }

    if (options.size) {
        await page.locator('label', { hasText: options.size }).click();
    }

    if (options.referenceImage) {
        await page.getByRole('button', { name: 'Add reference image' }).click();
        await page.getByLabel('Reference image 1', { exact: true }).fill(options.referenceImage);
    }

    await page.getByRole('button', { name: 'Generate', exact: true }).click();
    await expect(page).toHaveURL('/generations');
}

/** Marks this document, so a full reload (which would drop the mark) can be detected. */
export async function markNoReload(page: Page): Promise<void> {
    await page.evaluate(() => {
        (window as Window & { e2eNoReload?: boolean }).e2eNoReload = true;
    });
}

/** The document marked by markNoReload is still the one on screen. */
export async function expectNoReload(page: Page): Promise<void> {
    const isSameDocument = await page.evaluate(() => (window as Window & { e2eNoReload?: boolean }).e2eNoReload === true);

    expect(isSameDocument, 'the page was not reloaded').toBe(true);
}

/** History's cards. */
export function historyItems(page: Page): Locator {
    return page.getByRole('main').getByRole('listitem');
}

/** A card's status badge. */
export function statusOf(item: Locator): Locator {
    return item.locator('[data-tone]').first();
}

/**
 * The integer n$ in an amount's exact tip, such as 381,887,500 for `$0.381887500`. The
 * tip is hidden until hovered, so it is read from the DOM.
 */
export async function exactNanodollars(tooltip: Locator): Promise<number> {
    const text = ((await tooltip.textContent()) ?? '').trim();
    const match = /^\$(\d[\d,]*)\.(\d{9})$/.exec(text);

    if (!match) {
        throw new Error(`"${text}" is not an exact amount.`);
    }

    return Number(match[1]!.replaceAll(',', '')) * 1_000_000_000 + Number(match[2]);
}

/** A card's exact charge, then the exact bounds of its estimate. */
export async function exactChargeAndEstimate(item: Locator): Promise<{ charge: number; min: number; max: number }> {
    const bounds = item.locator('[data-estimate] [role="tooltip"]');

    return {
        charge: await exactNanodollars(item.locator('[data-charge] [role="tooltip"]')),
        min: await exactNanodollars(bounds.first()),
        max: await exactNanodollars(bounds.last()),
    };
}
