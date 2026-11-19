<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Enums;

use App\Domain\Shared\Enums\ImageSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

final class ImageSizeTest extends TestCase
{
    public function test_values_are_pixelforges_sizes_in_its_order(): void
    {
        $this->assertSame(
            ['1024x1024', '1536x1024', '1024x1536'],
            array_map(fn (ImageSize $size): string => $size->value, ImageSize::cases()),
        );
    }

    public function test_cases_name_the_orientation(): void
    {
        $this->assertSame(ImageSize::Square, ImageSize::from('1024x1024'));
        $this->assertSame(ImageSize::Landscape, ImageSize::from('1536x1024'));
        $this->assertSame(ImageSize::Portrait, ImageSize::from('1024x1536'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownSizes(): array
    {
        return [
            'unsupported size' => ['512x512'],
            'upper-case X' => ['1024X1024'],
            'multiplication sign' => ["1024\u{00D7}1024"],
            'leading space' => [' 1024x1024'],
        ];
    }

    #[DataProvider('unknownSizes')]
    public function test_rejects_a_string_that_is_not_an_exact_size(string $size): void
    {
        $this->expectException(ValueError::class);

        $this->fail(sprintf('"%s" was accepted as %s.', $size, ImageSize::from($size)->name));
    }
}
