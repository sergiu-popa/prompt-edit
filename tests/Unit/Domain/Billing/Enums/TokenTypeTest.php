<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Enums;

use App\Domain\Billing\Enums\TokenType;
use PHPUnit\Framework\TestCase;

final class TokenTypeTest extends TestCase
{
    public function test_only_reference_image_and_output_tokens_are_known_up_front(): void
    {
        $this->assertSame(
            ['input_text_tokens' => false, 'input_image_tokens' => true, 'output_tokens' => true, 'reasoning_tokens' => false],
            array_combine(TokenType::keys(), array_map(fn (TokenType $type): bool => $type->isKnownUpfront(), TokenType::cases())),
        );
    }
}
