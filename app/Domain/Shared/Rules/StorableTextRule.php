<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Text the app can store and send exactly as given: valid UTF-8 with no U+0000.
 * Postgres text cannot hold a NUL (pdo_pgsql silently cuts the value at it) and
 * refuses invalid UTF-8 outright, and the PHP and TS estimators count invalid UTF-8
 * differently, so either is a field error here. Generate uses it for the prompt and
 * the reference URLs, where CreateGenerationAction still refuses a NUL as the last
 * line, and sign-up for the name.
 */
final class StorableTextRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            $fail(":Attribute contains characters that can't be sent.");
        }
    }
}
