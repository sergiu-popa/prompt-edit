<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Instants compared in UTC, whatever timezone they carry. It works without
 * booting the application, so plain PHPUnit tests can use it too.
 *
 * @phpstan-require-extends TestCase
 */
trait AssertsInstants
{
    /**
     * @param  string  $expected  a whole-second UTC instant, e.g. `2026-11-18T23:59:59Z`
     */
    protected function assertSameInstant(string $expected, mixed $actual): void
    {
        $this->assertInstanceOf(CarbonImmutable::class, $actual);
        $this->assertSame($expected, $actual->utc()->format('Y-m-d\TH:i:s\Z'));
    }
}
