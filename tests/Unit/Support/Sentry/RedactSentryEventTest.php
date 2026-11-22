<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sentry;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Support\Sentry\RedactSentryEvent;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Stacktrace;
use Sentry\Tracing\Span;
use Sentry\UserDataBag;

final class RedactSentryEventTest extends TestCase
{
    private const string API_KEY = 'pf_test_0123456789abcdefghij';

    private const string WEBHOOK_SECRET = 'pfwh_9f8e7d6c5b4a39281706';

    /**
     * Each kind of secret, as the text it hides in and the part of it that must never be sent.
     */
    #[DataProvider('secrets')]
    public function test_it_removes_the_secret_from_every_part_of_an_event(string $text, string $secret): void
    {
        $event = $this->redact($this->eventCarrying($text));
        $payload = $this->payload($event);

        $this->assertStringNotContainsString($secret, $payload);
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, (string) $event->getMessageFormatted());
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, $event->getMessageParams()[0]);
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, $event->getExceptions()[0]->getValue());
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, (string) $event->getBreadcrumbs()[0]->getMessage());
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, $this->stringAt($event->getBreadcrumbs()[0]->getMetadata(), 'detail'));
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, $this->stringAt($event->getExtra(), 'log_context', 'detail'));
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, $this->stringAt($event->getContexts(), 'laravel', 'detail'));
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, $event->getTags()['detail']);
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, $this->stringAt($event->getRequest(), 'headers', 'X-Detail', 0));
        $this->assertStringContainsString(RedactSentryEvent::FILTERED, (string) $event->getTransaction());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function secrets(): array
    {
        return [
            'the configured Pixelforge API key' => ['sent with key ' . self::API_KEY . ' today', self::API_KEY],
            'the configured webhook secret' => ['signed with ' . self::WEBHOOK_SECRET . ' today', self::WEBHOOK_SECRET],
            'any whsec_ string' => ['signed with whsec_Zm9vYmFyYmF6cXV4 today', 'whsec_Zm9vYmFyYmF6cXV4'],
            'a bearer token' => ['sent Authorization: Bearer tok_abc123XYZ today', 'tok_abc123XYZ'],
            'a basic credential' => ['sent Authorization: Basic dXNlcjpodW50ZXIy today', 'dXNlcjpodW50ZXIy'],
            'an Authorization value without a scheme' => ['sent {"Authorization":"tok_noscheme987"} today', 'tok_noscheme987'],
            'an email address' => ['signed in as Reviewer.One+demo@promptedit.test today', 'promptedit.test'],
        ];
    }

    public function test_it_leaves_ordinary_text_alone(): void
    {
        $context = [
            'generation_id' => '01JC2Z9A7M4K2P8Q6R3T5V1W9X',
            'status' => 'processing',
            'input_tokens' => 1200,
            'retry_after_seconds' => null,
            'succeeded' => true,
            'error' => 'The generation object\'s usage is invalid: input_tokens is missing.',
        ];
        $event = Event::createEvent();
        $event->setMessage('The reconciler could not handle a generation; it carries on with the rest.');
        $event->setExtra(['log_context' => $context]);
        $event->setTransaction('/generations/{generation}');

        $event = $this->redact($event);

        $this->assertSame('The reconciler could not handle a generation; it carries on with the rest.', $event->getMessage());
        $this->assertSame(['log_context' => $context], $event->getExtra());
        $this->assertSame('/generations/{generation}', $event->getTransaction());
    }

    public function test_it_redacts_nested_arrays_and_keeps_their_shape(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['log_context' => [
            'attempts' => [
                ['user' => ['email' => 'demo@promptedit.test', 'id' => 7]],
                ['header' => ['Bearer tok_nested42', 'keep me']],
            ],
        ]]);

        $event = $this->redact($event);

        $this->assertSame(['log_context' => [
            'attempts' => [
                ['user' => ['email' => RedactSentryEvent::FILTERED, 'id' => 7]],
                ['header' => ['Bearer ' . RedactSentryEvent::FILTERED, 'keep me']],
            ],
        ]], $event->getExtra());
    }

    /**
     * A value is filtered by its key's name even when nothing in it looks like a secret.
     */
    public function test_it_filters_the_values_of_sensitive_keys(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['log_context' => [
            'password' => 'correct horse battery staple',
            'prompt' => 'a red fox in fresh snow',
            'Authorization' => ['pf_live_plain'],
            'purchase_token' => '9b0c6f1e',
            'webhook_secret' => 'anything',
            'api_key' => 'anything',
            'ip_address' => '203.0.113.7',
            'X-Forwarded-For' => ['203.0.113.7'],
            'cookie' => 'laravel_session=abc',
            'input_tokens' => 1200,
            'retry_after_seconds' => 30,
        ]]);

        $event = $this->redact($event);

        $this->assertSame(['log_context' => [
            'password' => RedactSentryEvent::FILTERED,
            'prompt' => RedactSentryEvent::FILTERED,
            'Authorization' => [RedactSentryEvent::FILTERED],
            'purchase_token' => RedactSentryEvent::FILTERED,
            'webhook_secret' => RedactSentryEvent::FILTERED,
            'api_key' => RedactSentryEvent::FILTERED,
            'ip_address' => RedactSentryEvent::FILTERED,
            'X-Forwarded-For' => [RedactSentryEvent::FILTERED],
            'cookie' => RedactSentryEvent::FILTERED,
            'input_tokens' => 1200,
            'retry_after_seconds' => 30,
        ]], $event->getExtra());
    }

    /**
     * The package records every Artisan command line as a breadcrumb's `input`, and
     * `app:upsert-user` takes the account's display name as an argument.
     */
    public function test_it_filters_the_arguments_of_a_command_line(): void
    {
        $event = Event::createEvent();
        $event->setBreadcrumb([new Breadcrumb('info', 'default', 'artisan.command', 'Starting Artisan command: app:upsert-user', [
            'input' => "'app:upsert-user' reviewer@promptedit.test 'Ada Lovelace'",
        ])]);

        $event = $this->redact($event);

        $this->assertSame('Starting Artisan command: app:upsert-user', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertSame(['input' => RedactSentryEvent::FILTERED], $event->getBreadcrumbs()[0]->getMetadata());
    }

    /**
     * Laravel writes a failed query's bindings into its message, and PostgreSQL's DETAIL line
     * repeats the row or key values. The SQL keeps its placeholders.
     */
    public function test_it_removes_the_bindings_and_detail_values_of_a_failed_query(): void
    {
        $exception = new QueryException(
            'pgsql',
            'insert into "users" ("email", "name", "password") values (?, ?, ?)',
            ['demo@promptedit.test', 'Ada Lovelace', '$2y$04$abcdefghijklmnopqrstuv'],
            new PDOException("SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"created_at\" violates not-null constraint\nDETAIL:  Failing row contains (7, Ada Lovelace, \$2y\$04\$abcdefghijklmnopqrstuv, null)."),
        );
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag($exception), new ExceptionDataBag($this->previousOf($exception))]);
        $event->setBreadcrumb([new Breadcrumb('error', 'default', 'log.error', $exception->getMessage(), ['exception' => $exception])]);

        $event = $this->redact($event, EventHint::fromArray(['exception' => $exception]));
        $payload = $this->payload($event);

        $this->assertStringContainsString('SQL: insert into "users" ("email", "name", "password") values (?, ?, ?))', $event->getExceptions()[0]->getValue());
        $this->assertStringContainsString('DETAIL:  ' . RedactSentryEvent::FILTERED, $event->getExceptions()[0]->getValue());
        $this->assertStringContainsString('null value in column "created_at" violates not-null constraint', $event->getExceptions()[1]->getValue());
        $this->assertStringContainsString('DETAIL:  ' . RedactSentryEvent::FILTERED, $event->getExceptions()[1]->getValue());
        $this->assertSame($event->getExceptions()[0]->getValue(), $event->getBreadcrumbs()[0]->getMessage());
        $this->assertSame(['exception' => QueryException::class], $event->getBreadcrumbs()[0]->getMetadata());
        $this->assertStringNotContainsString('Ada Lovelace', $payload);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuv', $payload);
        $this->assertStringNotContainsString('promptedit.test', $payload);
    }

    /**
     * A query that failed earlier in the request reaches a later event only as a breadcrumb.
     */
    public function test_it_removes_the_bindings_of_a_failed_query_found_in_a_breadcrumb(): void
    {
        $exception = new QueryException(
            'pgsql',
            'update "generations" set "prompt" = ? where "id" = ?',
            ['a red fox in fresh snow', 42],
            new PDOException('SQLSTATE[22001]: String data, right truncated: 7 ERROR:  value too long for type character varying(10)'),
        );
        $event = Event::createEvent();
        $event->setMessage('Refused a Pixelforge webhook: no webhook secret is configured.');
        $event->setBreadcrumb([new Breadcrumb('error', 'default', 'log.error', $exception->getMessage(), ['exception' => $exception])]);

        $event = $this->redact($event);

        $this->assertStringEndsWith('SQL: update "generations" set "prompt" = ? where "id" = ?)', (string) $event->getBreadcrumbs()[0]->getMessage());
        $this->assertStringNotContainsString('red fox', $this->payload($event));
    }

    /**
     * Function arguments can hold a password or a prompt that no pattern recognises.
     */
    public function test_it_drops_every_stack_frame_variable(): void
    {
        $frame = new Frame('attempt', 'app/Actions/Fortify/AuthenticateUser.php', 12, null, null, ['password' => 'hunter2', 'prompt' => 'a lighthouse']);
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('Boom'), new Stacktrace([$frame]))]);
        $event->setStacktrace(new Stacktrace([new Frame('handle', 'app/Jobs/SubmitGenerationJob.php', 40, null, null, ['prompt' => 'a lighthouse'])]));

        $event = $this->redact($event);

        $this->assertSame([], $event->getExceptions()[0]->getStacktrace()?->getFrames()[0]->getVars());
        $this->assertSame([], $event->getStacktrace()?->getFrames()[0]->getVars());
    }

    public function test_it_drops_the_request_body_cookies_and_server_variables(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://prompt.sergiupopa.dev/generations',
            'method' => 'POST',
            'query_string' => 'page=2',
            'data' => ['prompt' => 'a red fox', 'password' => 'hunter2'],
            'cookies' => ['laravel_session' => 'abc'],
            'env' => ['REMOTE_ADDR' => '203.0.113.7'],
            'headers' => ['Accept' => ['text/html'], 'Authorization' => ['Bearer tok_header1']],
        ]);

        $event = $this->redact($event);

        $this->assertSame([
            'url' => 'https://prompt.sergiupopa.dev/generations',
            'method' => 'POST',
            'query_string' => 'page=2',
            'headers' => ['Accept' => ['text/html'], 'Authorization' => [RedactSentryEvent::FILTERED]],
        ], $event->getRequest());
    }

    /**
     * `user_info` is off, but a log line's `user` context would still set one.
     */
    public function test_it_keeps_only_the_users_id(): void
    {
        $event = Event::createEvent();
        $event->setUser(UserDataBag::createFromArray(['id' => 7, 'email' => 'demo@promptedit.test', 'ip_address' => '203.0.113.7', 'username' => 'demo']));

        $event = $this->redact($event);

        $user = $event->getUser();

        $this->assertNotNull($user);
        $this->assertSame(7, $user->getId());
        $this->assertNull($user->getEmail());
        $this->assertNull($user->getIpAddress());
        $this->assertNull($user->getUsername());
    }

    public function test_it_drops_a_user_without_an_id(): void
    {
        $event = Event::createEvent();
        $event->setUser(UserDataBag::createFromUserIpAddress('203.0.113.7'));

        $this->assertNull($this->redact($event)->getUser());
    }

    /**
     * Objects become what they mean as text, so nothing reaches the payload unredacted.
     */
    public function test_it_turns_objects_into_redacted_text(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['log_context' => [
            'previous' => new RuntimeException('demo@promptedit.test'),
            'status' => GenerationStatus::Processing,
            'at' => new \DateTimeImmutable('2026-11-22T10:00:00Z'),
            'note' => new class implements \Stringable
            {
                public function __toString(): string
                {
                    return 'Bearer tok_note55';
                }
            },
            'other' => new \stdClass,
        ]]);

        $event = $this->redact($event);

        $this->assertSame(['log_context' => [
            'previous' => RuntimeException::class,
            'status' => 'processing',
            'at' => '2026-11-22T10:00:00+00:00',
            'note' => 'Bearer ' . RedactSentryEvent::FILTERED,
            'other' => \stdClass::class,
        ]], $event->getExtra());
    }

    public function test_it_redacts_the_spans_of_a_transaction(): void
    {
        $span = new Span;
        $span->setDescription('GET https://pixelforge.promptedit.dev/v1/generations?owner=demo@promptedit.test');
        $span->setData(['http.request.header.authorization' => 'Bearer tok_span77', 'http.response.status_code' => 200]);
        $transaction = Event::createTransaction();
        $transaction->setSpans([$span]);

        $this->redact($transaction);

        $this->assertSame('GET https://pixelforge.promptedit.dev/v1/generations?owner=' . RedactSentryEvent::FILTERED, $span->getDescription());
        $this->assertSame(['http.request.header.authorization' => RedactSentryEvent::FILTERED, 'http.response.status_code' => 200], $span->getData());
    }

    /**
     * A short dev value such as `secret` would otherwise blank ordinary words.
     */
    public function test_a_configured_value_shorter_than_16_characters_is_not_treated_as_a_secret(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Refused a Pixelforge webhook: no webhook secret is configured.');

        (new RedactSentryEvent(['secret', '']))($event);

        $this->assertSame('Refused a Pixelforge webhook: no webhook secret is configured.', $event->getMessage());
    }

    private function redact(Event $event, ?EventHint $hint = null): Event
    {
        return (new RedactSentryEvent([self::API_KEY, self::WEBHOOK_SECRET]))($event, $hint);
    }

    private function eventCarrying(string $text): Event
    {
        $event = Event::createEvent();
        $event->setMessage('Failed: %s', [$text], 'Failed: ' . $text);
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('Pixelforge refused: ' . $text))]);
        $event->setBreadcrumb([new Breadcrumb('warning', 'default', 'log.warning', 'Earlier: ' . $text, ['detail' => $text])]);
        $event->setExtra(['log_context' => ['detail' => $text]]);
        $event->setContext('laravel', ['detail' => $text]);
        $event->setTags(['detail' => $text]);
        $event->setRequest(['url' => 'https://prompt.sergiupopa.dev/webhooks/pixelforge', 'headers' => ['X-Detail' => [$text]]]);
        $event->setTransaction('/webhooks/pixelforge ' . $text);

        return $event;
    }

    private function payload(Event $event): string
    {
        return (new PayloadSerializer(new Options))->serialize($event);
    }

    private function previousOf(QueryException $exception): PDOException
    {
        $previous = $exception->getPrevious();
        $this->assertInstanceOf(PDOException::class, $previous);

        return $previous;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function stringAt(array $data, string|int ...$path): string
    {
        foreach ($path as $key) {
            $this->assertIsArray($data);
            $data = $data[$key] ?? null;
        }

        $this->assertIsString($data);

        return $data;
    }
}
