<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Enums;

use App\Domain\Shared\Enums\ImageQuality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

final class ImageQualityTest extends TestCase
{
    public function test_values_are_pixelforges_qualities_in_its_order(): void
    {
        $this->assertSame(
            ['low', 'medium', 'high'],
            array_map(fn (ImageQuality $quality): string => $quality->value, ImageQuality::cases()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownQualities(): array
    {
        return [
            'unknown quality' => ['ultra'],
            'upper case' => ['LOW'],
            'case name instead of value' => ['Low'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unknownQualities')]
    public function test_rejects_a_string_that_is_not_an_exact_quality(string $quality): void
    {
        $this->expectException(ValueError::class);

        $this->fail(sprintf('"%s" was accepted as %s.', $quality, ImageQuality::from($quality)->name));
    }
}
