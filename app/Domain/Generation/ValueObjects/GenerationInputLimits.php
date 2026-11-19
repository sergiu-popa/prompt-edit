<?php

declare(strict_types=1);

namespace App\Domain\Generation\ValueObjects;

/**
 * The limits Pixelforge's create-generation endpoint puts on its inputs: the
 * Generate page's `limits` prop, and the rules PE-011's request applies.
 */
final class GenerationInputLimits
{
    /** Counted in code points, as PHP's mb_strlen and Pixelforge count them. */
    public const int MAX_PROMPT_CHARACTERS = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @return array{max_prompt_characters: int, max_reference_images: int}
     */
    public static function toArray(): array
    {
        return [
            'max_prompt_characters' => self::MAX_PROMPT_CHARACTERS,
            'max_reference_images' => self::MAX_REFERENCE_IMAGES,
        ];
    }
}
