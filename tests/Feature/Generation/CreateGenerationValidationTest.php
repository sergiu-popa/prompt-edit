<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SubmitsGenerations;
use Tests\TestCase;

/**
 * The server is the authority on what may be generated (AC 1–3 and 5). Every
 * rejection sends the user back to Generate with the error on its field, and
 * writes no generation, hold or job.
 */
final class CreateGenerationValidationTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsGenerations;

    /**
     * 3,998 × `a` + 2 × 🎨: 4,000 code points, 4,002 UTF-16 units and 4,006 bytes.
     */
    public function test_a_prompt_of_4000_code_points_is_accepted_whatever_its_bytes(): void
    {
        $prompt = str_repeat('a', 3998) . '🎨🎨';
        $this->assertSame([4000, 4006], [mb_strlen($prompt), strlen($prompt)]);

        $this->submitGeneration(['prompt' => $prompt])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('generations.index'));

        $this->assertSame($prompt, Generation::query()->sole()->prompt);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function rejectedFields(): array
    {
        $tooLongPrompt = str_repeat('a', 3999) . '🎨🎨';
        $longestUrl = 'https://example.com/' . str_repeat('a', 2028);

        return [
            'a prompt of 4,001 code points' => [['prompt' => $tooLongPrompt], 'prompt', 'The prompt must be 4,000 characters or fewer.'],
            'no prompt' => [['prompt' => null], 'prompt', 'Enter a prompt.'],
            'an empty prompt' => [['prompt' => ''], 'prompt', 'Enter a prompt.'],
            'a prompt of spaces' => [['prompt' => '   '], 'prompt', 'Enter a prompt.'],
            'a prompt of a newline and a tab' => [['prompt' => "\n\t"], 'prompt', 'Enter a prompt.'],
            'a prompt array' => [['prompt' => ['A lighthouse']], 'prompt', 'Enter a prompt.'],
            'a size Pixelforge does not offer' => [['size' => '512x512'], 'size', 'Choose a size.'],
            'no size' => [['size' => null], 'size', 'Choose a size.'],
            'a quality Pixelforge does not offer' => [['quality' => 'ultra'], 'quality', 'Choose a quality.'],
            'no quality' => [['quality' => null], 'quality', 'Choose a quality.'],
            'a fifth reference' => [
                ['reference_images' => array_fill(0, 5, 'https://example.com/style.png')],
                'reference_images',
                'Add up to 4 reference images.',
            ],
            'an empty reference' => [['reference_images' => ['https://example.com/a.png', '']], 'reference_images.1', 'Enter an http:// or https:// image URL.'],
            'an ftp reference' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0', 'Enter an http:// or https:// image URL.'],
            'a javascript reference' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0', 'Enter an http:// or https:// image URL.'],
            'a reference with no scheme' => [['reference_images' => ['example.com/a.png']], 'reference_images.0', 'Enter an http:// or https:// image URL.'],
            'a reference with no host' => [['reference_images' => ['https://']], 'reference_images.0', 'Enter an http:// or https:// image URL.'],
            'a reference with a space in its host' => [['reference_images' => ['https://exa mple.com']], 'reference_images.0', 'Enter an http:// or https:// image URL.'],
            'a reference of 2,049 characters' => [['reference_images' => [$longestUrl . 'a']], 'reference_images.0', 'Use a URL of 2,048 characters or fewer.'],
            'a reference that is an array' => [['reference_images' => [['https://example.com/a.png']]], 'reference_images.0', 'Enter an http:// or https:// image URL.'],
            'references keyed out of order' => [['reference_images' => [5 => 'https://example.com/a.png']], 'reference_images', 'Reload the page and try again.'],
            'references that are not a list' => [['reference_images' => 'https://example.com/a.png'], 'reference_images', 'Reload the page and try again.'],
            'no submission token' => [['submission_token' => null], 'submission_token', 'Reload the page and try again.'],
            'a submission token that is not a UUID' => [['submission_token' => 'not-a-uuid'], 'submission_token', 'Reload the page and try again.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('rejectedFields')]
    public function test_a_rejected_field_sends_the_user_back_with_its_error_and_creates_nothing(array $overrides, string $key, string $message): void
    {
        $this->submitGeneration($overrides)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors([$key => $message]);

        $this->assertNothingWasCreated();
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function acceptedReferenceImages(): array
    {
        return [
            'none' => [[]],
            'the docs\' reference' => [['https://example.com/style.png']],
            'plain http' => [['http://x.test/a']],
            'an upper-case scheme and host' => [['HTTPS://EXAMPLE.COM/A.PNG']],
            'four' => [array_fill(0, 4, 'https://example.com/style.png')],
            'one of 2,048 characters' => [['https://example.com/' . str_repeat('a', 2028)]],
        ];
    }

    /**
     * @param  list<string>  $referenceImages
     */
    #[DataProvider('acceptedReferenceImages')]
    public function test_accepted_references_are_stored_as_sent(array $referenceImages): void
    {
        $this->submitGeneration(['reference_images' => $referenceImages])->assertSessionHasNoErrors();

        $this->assertSame($referenceImages, Generation::query()->sole()->reference_images);
    }

    public function test_a_submission_with_no_references_field_is_stored_with_none(): void
    {
        $this->submitGeneration(['reference_images' => null])->assertSessionHasNoErrors();

        $this->assertSame([], Generation::query()->sole()->reference_images);
    }

    /**
     * Duplicates are allowed, and Pixelforge bills each one 765 tokens.
     */
    public function test_a_duplicated_reference_is_stored_and_billed_twice(): void
    {
        $this->submitGeneration(['reference_images' => ['https://example.com/style.png', 'https://example.com/style.png']])
            ->assertSessionHasNoErrors();

        $generation = Generation::query()->sole();
        $this->assertSame(['https://example.com/style.png', 'https://example.com/style.png'], $generation->reference_images);
        $this->assertSame(466_712_500, $generation->user_estimate_max_nanodollars);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function unstorableText(): array
    {
        $cannotBeSent = "contains characters that can't be sent.";

        return [
            'a NUL at the start of the prompt' => [['prompt' => "\0A lighthouse"], 'prompt', "Prompt {$cannotBeSent}"],
            'a NUL in the middle of the prompt' => [['prompt' => "A light\0house"], 'prompt', "Prompt {$cannotBeSent}"],
            'a NUL at the end of the prompt' => [['prompt' => "A lighthouse\0"], 'prompt', "Prompt {$cannotBeSent}"],
            'invalid UTF-8 in the prompt' => [['prompt' => "A lighthouse \xFF"], 'prompt', "Prompt {$cannotBeSent}"],
            'a NUL inside a reference' => [
                ['reference_images' => ['https://example.com/a.png', "https://example.com/b\0.png"]],
                'reference_images.1',
                "Reference image 2 {$cannotBeSent}",
            ],
            'invalid UTF-8 in a reference' => [['reference_images' => ["https://example.com/\xFF.png"]], 'reference_images.0', "Reference image 1 {$cannotBeSent}"],
        ];
    }

    /**
     * Postgres text would silently cut a NUL, and invalid UTF-8 is counted differently
     * by the two estimators, so each is a field error, never a 500.
     *
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('unstorableText')]
    public function test_unstorable_text_is_a_field_error(array $overrides, string $key, string $message): void
    {
        $this->submitGeneration($overrides)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors([$key => $message]);

        $this->assertNothingWasCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invisiblePrompts(): array
    {
        return [
            'no-break spaces' => ["\u{00A0}\u{00A0}"],
            'ideographic spaces' => ["\u{3000}\u{3000}"],
            'a byte order mark' => ["\u{FEFF}"],
            'a line separator and an en space' => ["\u{2028}\u{2002}"],
            'a vertical tab and a form feed' => ["\v\f"],
        ];
    }

    /**
     * Blank by JS trim()'s set, as the Generate page judges it: ASCII whitespace, the
     * Unicode space separators and U+FEFF.
     */
    #[DataProvider('invisiblePrompts')]
    public function test_a_prompt_of_unicode_whitespace_is_blank(string $prompt): void
    {
        $this->submitGeneration(['prompt' => $prompt])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['prompt' => 'Enter a prompt.']);

        $this->assertNothingWasCreated();
    }

    /**
     * Unicode whitespace around visible text is kept, exactly as typed (D-37).
     */
    public function test_a_prompt_with_visible_text_inside_unicode_whitespace_is_accepted(): void
    {
        $this->submitGeneration(['prompt' => "\u{3000}A lighthouse\u{00A0}"])->assertSessionHasNoErrors();

        $this->assertSame("\u{3000}A lighthouse\u{00A0}", Generation::query()->sole()->prompt);
    }

    /**
     * PHP cannot decode an unpaired surrogate escape, so the body reads as empty: the
     * user gets required-field errors and nothing is created.
     */
    public function test_a_json_body_with_an_unpaired_surrogate_gets_required_field_errors(): void
    {
        $body = '{"prompt":"A lighthouse \\ud800","size":"1536x1024","quality":"high","submission_token":"0b4f6c1e-8f6a-4c2e-9d3b-2a7e5c9f1b40"}';

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->call('POST', route('generations.store'), server: ['CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['prompt' => 'Enter a prompt.', 'size' => 'Choose a size.', 'quality' => 'Choose a quality.']);

        $this->assertNothingWasCreated();
    }
}
