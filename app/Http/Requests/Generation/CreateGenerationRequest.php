<?php

declare(strict_types=1);

namespace App\Http\Requests\Generation;

use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\ValueObjects\GenerationInputLimits;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'prompt' => ['bail', 'required', 'string', 'max:' . GenerationInputLimits::MAX_PROMPT_CHARACTERS],
            'size' => ['bail', 'required', Rule::enum(ImageSize::class)],
            'quality' => ['bail', 'required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['bail', 'sometimes', 'array', 'list', 'max:' . GenerationInputLimits::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['bail', 'required', 'string', 'max:' . self::MAX_REFERENCE_URL_CHARACTERS, 'url:http,https'],
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
