<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use LogicException;

/**
 * The lock that keeps at most one Pixelforge create in flight for a generation
 * (D-53). It owns the key, the store and the TTL: `pixelforge-create:{ulid}` on the
 * pinned database store every process shares, held past the submit job's timeout,
 * so a run killed at its timeout still holds the next one off.
 */
final readonly class PixelforgeCreateLock
{
    /**
     * The lock's key, before the ULID.
     */
    public const string KEY_PREFIX = 'pixelforge-create:';

    public function __construct(
        private CacheFactory $cache,
        #[Config('pixelforge.rate_limit_store')]
        private string $store,
        #[Config('pixelforge.submit.create_lock_seconds')]
        private int $seconds,
    ) {}

    /**
     * @throws LogicException when the pinned store cannot lock, which config rules out
     */
    public function for(string $generationId): Lock
    {
        $store = $this->cache->store($this->store)->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException(sprintf('The "%s" cache store cannot hold the create lock.', $this->store));
        }

        return $store->lock(self::KEY_PREFIX . $generationId, $this->seconds);
    }
}
