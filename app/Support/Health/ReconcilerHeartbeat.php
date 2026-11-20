<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * When the reconciler last completed a run (D-59), as unix seconds under a forever
 * key on the pinned database store the cron and PHP-FPM share. Deploys never clear
 * it; after a `cache:clear` it reads stale until the next run.
 */
final readonly class ReconcilerHeartbeat
{
    public const string KEY = 'reconciler:last-succeeded-at';

    /**
     * A run every minute leaves plenty of slack below this.
     */
    public const int STALE_AFTER_SECONDS = 300;

    public function __construct(
        private CacheFactory $cache,
        #[Config('pixelforge.rate_limit_store')]
        private string $store,
    ) {}

    public function recordSuccessfulRun(CarbonImmutable $at): void
    {
        $this->repository()->forever(self::KEY, $at->getTimestamp());
    }

    public function lastSuccessfulRunAt(): ?CarbonImmutable
    {
        $timestamp = $this->repository()->get(self::KEY);

        return is_int($timestamp) ? CarbonImmutable::createFromTimestampUTC($timestamp) : null;
    }

    /**
     * Stale once the last run is more than 5 min old, or before the first one.
     */
    public function isStale(CarbonImmutable $now): bool
    {
        $lastRunAt = $this->lastSuccessfulRunAt();

        return $lastRunAt === null || $now->getTimestamp() - $lastRunAt->getTimestamp() > self::STALE_AFTER_SECONDS;
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
