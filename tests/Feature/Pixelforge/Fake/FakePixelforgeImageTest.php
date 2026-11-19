<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * The fake's placeholder PNG, behind a root-relative signed URL so the same
 * URL loads in dev (`prompt-edit.localhost`) and in E2E (`127.0.0.1:8080`).
 * It is served only while the fake is the driver.
 */
final class FakePixelforgeImageTest extends TestCase
{
    use InteractsWithFakePixelforge;

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private string $providerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerId = 'gen_' . Str::ulid();
    }

    public function test_a_signed_url_has_no_scheme_or_host_and_loads_the_png(): void
    {
        $url = $this->signedImageUrl();

        $this->assertStringStartsWith('/fake-pixelforge/images/' . $this->providerId . '.png?', $url);
        $this->assertNull(parse_url($url, PHP_URL_SCHEME));
        $this->assertNull(parse_url($url, PHP_URL_HOST));

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith(self::PNG_SIGNATURE, self::bodyOf($response));
    }

    public function test_the_image_is_not_found_while_the_fake_is_inactive(): void
    {
        config(['pixelforge.driver' => 'http']);

        $this->get($this->signedImageUrl())->assertNotFound();
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $url = $this->signedImageUrl();

        $this->get((string) preg_replace('/signature=[0-9a-f]/', 'signature=x', $url))->assertForbidden();
    }

    public function test_a_url_for_another_generation_is_refused(): void
    {
        $url = str_replace($this->providerId, 'gen_' . Str::ulid(), $this->signedImageUrl());

        $this->get($url)->assertForbidden();
    }

    /**
     * Like a dead signed URL: it loads up to its expiry, and is refused after.
     */
    public function test_an_expired_url_is_refused(): void
    {
        $expiresAt = CarbonImmutable::now()->addMinute();
        $url = URL::temporarySignedRoute('fake-pixelforge.image', $expiresAt, ['generation' => $this->providerId], absolute: false);

        $this->travelTo($expiresAt);
        $this->get($url)->assertOk();

        $this->travelTo($expiresAt->addSecond());
        $this->get($url)->assertForbidden();
    }

    /**
     * A browser must ask again on every load, so it never shows an expired
     * image from its own cache.
     */
    public function test_the_image_is_never_cached(): void
    {
        $cacheControl = (string) $this->get($this->signedImageUrl())->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_a_missing_signature_is_refused(): void
    {
        $this->get('/fake-pixelforge/images/' . $this->providerId . '.png')->assertForbidden();
    }

    private function signedImageUrl(): string
    {
        return URL::temporarySignedRoute(
            'fake-pixelforge.image',
            CarbonImmutable::now()->addDay(),
            ['generation' => $this->providerId],
            absolute: false,
        );
    }

    private static function bodyOf(TestResponse $response): string
    {
        $file = $response->baseResponse;
        self::assertInstanceOf(BinaryFileResponse::class, $file);

        return (string) file_get_contents($file->getFile()->getPathname());
    }
}
