<?php

/**
 * A second process for PixelforgeWebhookConcurrencyTest, with its own
 * database connection: it sends the raw body in the second argument, signed
 * with the `Pixelforge-Signature` value in the first, through the app's HTTP
 * kernel to `POST /webhooks/pixelforge`, and prints the response status as
 * JSON. The secret comes from `PIXELFORGE_WEBHOOK_SECRET`. Not a PHPUnit file.
 *
 * The body is an argument, not stdin: the parent only feeds a child's stdin
 * while it waits on it, and it must first see both children wait on the lock.
 */

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__ . '/../../bootstrap/app.php';

$signature = $argv[1] ?? throw new InvalidArgumentException('Pass the Pixelforge-Signature value as the first argument.');
$body = $argv[2] ?? throw new InvalidArgumentException('Pass the raw body as the second argument.');

$request = Request::create('/webhooks/pixelforge', 'POST', server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
], content: $body);

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR);
