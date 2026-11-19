<?php

declare(strict_types=1);

namespace App\Http\Requests\Generation;

use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Rules\StorableTextRule;
use App\Domain\Generation\ValueObjects\GenerationInputLimits;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Generate form's submission. The server is the authority: the prompt is counted
 * in code points (`max` uses mb_strlen, as Pixelforge counts), references are checked
 * for shape only and never fetched (requirements §6), and only these fields reach the
 * domain, so a client can never set a price, an owner or metadata.
 *
 * The prompt arrives exactly as typed, since it is exempt from TrimStrings (D-37).
 */
final class CreateGenerationRequest extends FormRequest
{
    /** Ours: Pixelforge documents no bound on a reference URL. */
    public const int MAX_REFERENCE_URL_CHARACTERS = 2048;

    /**
     * Blank by JS trim()'s set, as the Generate page judges it: ASCII whitespace, the
     * Unicode space and line separators (`\p{Z}`) and U+FEFF. `required` covers ASCII.
     */
    private const string BLANK_PROMPT_PATTERN = '/^[\t\n\x{0B}\f\r\p{Z}\x{FEFF}]*$/u';

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
            'prompt' => [
                'bail',
                'required',
                'string',
                new StorableTextRule,
                'not_regex:' . self::BLANK_PROMPT_PATTERN,
                'max:' . GenerationInputLimits::MAX_PROMPT_CHARACTERS,
            ],
            'size' => ['bail', 'required', Rule::enum(ImageSize::class)],
            'quality' => ['bail', 'required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['bail', 'sometimes', 'array', 'list', 'max:' . GenerationInputLimits::MAX_REFERENCE_IMAGES],
            'reference_images.*' => [
                'bail',
                'required',
                'string',
                new StorableTextRule,
                'max:' . self::MAX_REFERENCE_URL_CHARACTERS,
                'url:http,https',
            ],
            'submission_token' => ['bail', 'required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $enterAPrompt = 'Enter a prompt.';
        $enterAUrl = 'Enter an http:// or https:// image URL.';
        $reload = 'Reload the page and try again.';

        return [
            'prompt.required' => $enterAPrompt,
            'prompt.string' => $enterAPrompt,
            'prompt.not_regex' => $enterAPrompt,
            'prompt.max' => sprintf('The prompt must be %s characters or fewer.', number_format(GenerationInputLimits::MAX_PROMPT_CHARACTERS)),
            'size.required' => 'Choose a size.',
            'size.enum' => 'Choose a size.',
            'quality.required' => 'Choose a quality.',
            'quality.enum' => 'Choose a quality.',
            'reference_images.array' => $reload,
            'reference_images.list' => $reload,
            'reference_images.max' => sprintf('Add up to %d reference images.', GenerationInputLimits::MAX_REFERENCE_IMAGES),
            'reference_images.*.required' => $enterAUrl,
            'reference_images.*.string' => $enterAUrl,
            'reference_images.*.max' => sprintf('Use a URL of %s characters or fewer.', number_format(self::MAX_REFERENCE_URL_CHARACTERS)),
            'reference_images.*.url' => $enterAUrl,
            'submission_token.required' => $reload,
            'submission_token.uuid' => $reload,
        ];
    }

    /**
     * Generate stays closed until Pixelforge is configured (D-40), so nothing is written
     * that could never be submitted. The error belongs to the form, not a field.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(PixelforgeClient $pixelforge): array
    {
        return [
            function (Validator $validator) use ($pixelforge): void {
                if (! $pixelforge->isConfigured()) {
                    $validator->errors()->add('submission', "Image generation isn't available yet.");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'prompt' => 'Prompt',
            'reference_images.*' => 'Reference image :position',
        ];
    }

    /**
     * Built from the validated fields only.
     */
    public function toNewGenerationData(): NewGenerationData
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: list<string>, submission_token: string} $validated */
        $validated = $this->validated();

        return new NewGenerationData(
            $validated['prompt'],
            ImageSize::from($validated['size']),
            ImageQuality::from($validated['quality']),
            $validated['reference_images'] ?? [],
            $validated['submission_token'],
        );
    }
}
