<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * The docs' test keywords found in a prompt. Each is matched with its
 * brackets, anywhere in the prompt, ignoring ASCII case.
 *
 * Precedence: a rejection comes first and stores nothing, `[blocked]` before
 * `[overloaded]`. An accepted create then ends as `[fail]` decides. The
 * modifier `[slow]` combines with any accepted outcome.
 */
final readonly class FakeKeywords
{
    private function __construct(
        public bool $blocked,
        public bool $overloaded,
        public bool $fail,
        public bool $slow,
    ) {}

    public static function fromPrompt(string $prompt): self
    {
        return new self(
            self::has($prompt, '[blocked]'),
            self::has($prompt, '[overloaded]'),
            self::has($prompt, '[fail]'),
            self::has($prompt, '[slow]'),
        );
    }

    /**
     * The error a create is refused with, or null when it is accepted.
     */
    public function rejection(): ?PixelforgeErrorCode
    {
        if ($this->blocked) {
            return PixelforgeErrorCode::ContentPolicyViolation;
        }

        if ($this->overloaded) {
            return PixelforgeErrorCode::Overloaded;
        }

        return null;
    }

    /**
     * How an accepted create ends, or null when no keyword decides.
     */
    public function outcome(): ?FakeGenerationOutcome
    {
        if ($this->fail) {
            return FakeGenerationOutcome::GenerationFailed;
        }

        return null;
    }

    /**
     * `stripos` folds ASCII only and compares bytes, so it is safe on UTF-8.
     */
    private static function has(string $prompt, string $keyword): bool
    {
        return stripos($prompt, $keyword) !== false;
    }
}
