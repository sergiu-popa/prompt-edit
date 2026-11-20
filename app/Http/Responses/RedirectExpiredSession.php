<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * An expired session never shows a raw 419 page or Inertia's error modal (D-60).
 * A 419 on anything but a plain JSON call becomes a 303 back to the previous page,
 * when that page is on our own host, else to Sign in, flashing the notice. A guest's
 * unsafe request to a signed-in route, which is how an expired session usually
 * arrives from a modern browser (Laravel lets a same-origin request through without
 * a token), keeps its redirect to Sign in and gains the same notice. Every other
 * response passes through unchanged.
 *
 * It is the app's one `respond()` callback, so a future custom error page goes here.
 */
final class RedirectExpiredSession
{
    public const string NOTICE = 'Your session expired. Please try again.';

    public const string FLASH_KEY = 'notice';

    private const int PAGE_EXPIRED = 419;

    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if ($response->getStatusCode() === self::PAGE_EXPIRED && ! $this->isPlainJsonCall($request)) {
            Inertia::flash(self::FLASH_KEY, self::NOTICE);

            return redirect()->to($this->previousPageOnOurHost($request) ?? route('login'), 303);
        }

        if ($exception instanceof AuthenticationException && ! $request->isMethodSafe() && $response->isRedirect() && $request->hasSession()) {
            Inertia::flash(self::FLASH_KEY, self::NOTICE);
        }

        return $response;
    }

    private function isPlainJsonCall(Request $request): bool
    {
        return $request->expectsJson() && ! $request->hasHeader('X-Inertia');
    }

    /**
     * Only our own host is a target, so a cross-site form can't bounce through us.
     */
    private function previousPageOnOurHost(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) === 0 ? $referer : null;
    }
}
