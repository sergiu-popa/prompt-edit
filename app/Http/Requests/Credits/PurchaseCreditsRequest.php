<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use App\Domain\Billing\ValueObjects\Nanodollars;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Buy credits pack: one of the configured packs, never an amount the client picks,
 * and the page visit's purchase token.
 */
final class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'pack_nanodollars' => ['bail', 'required', 'integer', Rule::in(self::packs())],
            'purchase_token' => ['bail', 'required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $chooseAPack = 'Choose one of the packs.';
        $reload = 'Reload the page and try again.';

        return [
            'pack_nanodollars.required' => $chooseAPack,
            'pack_nanodollars.integer' => $chooseAPack,
            'pack_nanodollars.in' => $chooseAPack,
            'purchase_token.required' => $reload,
            'purchase_token.uuid' => $reload,
        ];
    }

    public function toPack(): Nanodollars
    {
        return new Nanodollars($this->integer('pack_nanodollars'));
    }

    public function purchaseToken(): string
    {
        return $this->string('purchase_token')->toString();
    }

    /**
     * @return list<int>
     */
    private static function packs(): array
    {
        /** @var list<int> $packs */
        $packs = config()->array('credits.packs_nanodollars');

        return $packs;
    }
}
