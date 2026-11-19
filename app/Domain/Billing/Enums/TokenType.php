<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * The token types Pixelforge prices, keyed as in a generation's `rates` and `usage` objects.
 */
enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';

    /**
     * Whether an estimate knows this type's count exactly before the generation runs.
     */
    public function isKnownUpfront(): bool
    {
        return match ($this) {
            self::InputImage, self::Output => true,
            self::InputText, self::Reasoning => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
