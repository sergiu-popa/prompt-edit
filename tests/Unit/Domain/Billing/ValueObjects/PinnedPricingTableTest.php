<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\ValueObjects;

use App\Domain\Billing\ValueObjects\PricingTable;
use Tests\Concerns\InteractsWithPricing;
use Tests\TestCase;

/**
 * The table the app resolves from config('pricing') is the one both estimate
 * suites price against, and its array form is the Generate page's prop shape.
 */
final class PinnedPricingTableTest extends TestCase
{
    use InteractsWithPricing;

    public function test_the_resolved_table_is_exactly_the_fixture_pricing_block(): void
    {
        $this->assertSame(
            self::estimatesFixture()['pricing'],
            $this->app->make(PricingTable::class)->toArray(),
        );
    }

    public function test_the_table_is_built_once_per_application(): void
    {
        $this->assertSame(
            $this->app->make(PricingTable::class),
            $this->app->make(PricingTable::class),
        );
    }

    public function test_config_holds_the_pinned_snapshot_verbatim(): void
    {
        $this->assertSame(self::pinnedPricingSnapshot(), config('pricing'));
    }
}
