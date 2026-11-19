<?php

declare(strict_types=1);

namespace Tests\Concerns;

use RuntimeException;

/**
 * Pixelforge generation objects, from the docs' examples or shaped for a row.
 */
trait InteractsWithGenerationSnapshots
{
    /**
     * A JSON example copied verbatim from the Pixelforge docs, in tests/fixtures/pixelforge.
     *
     * @return array<string, mixed>
     */
    protected static function pixelforgeFixture(string $name): array
    {
        $fixture = json_decode(
            (string) file_get_contents(sprintf('%s/fixtures/pixelforge/%s.json', dirname(__DIR__), $name)),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($fixture)) {
            throw new RuntimeException(sprintf('tests/fixtures/pixelforge/%s.json must decode to an object.', $name));
        }

        /** @var array<string, mixed> $fixture */
        return $fixture;
    }
}
