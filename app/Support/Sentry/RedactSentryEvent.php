<?php

declare(strict_types=1);

namespace App\Support\Sentry;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use JsonSerializable;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Sentry\UserDataBag;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * The last step before an event or a transaction leaves for Sentry (D-77). `config/sentry.php`
 * already keeps bodies, cookies, users, SQL bindings and frame arguments out; this is the
 * defence in depth for what still travels as text: the message, exception values, breadcrumbs,
 * log context, contexts, tags, the request's headers and the spans.
 */
final readonly class RedactSentryEvent
{
    public const string FILTERED = '[Filtered]';

    /**
     * A configured value shorter than this is not treated as a secret, so a short dev value such
     * as `secret` never blanks ordinary words. Production's are far longer.
     */
    private const int MIN_SECRET_LENGTH = 16;

    /**
     * Deeper values are filtered, which also ends a JSON-serializable object that returns itself.
     */
    private const int MAX_DEPTH = 10;

    /**
     * A value under a key that matches is filtered, whatever it holds. `input` is the package's
     * breadcrumb of an Artisan command line, whose arguments can name a person.
     */
    private const string SENSITIVE_KEY = '/password|passwd|secret|authori[sz]ation|cookie|api[_-]?key|(?:^|[_-])token$|csrf|xsrf|prompt|^ip(?:_address)?$|remote_addr|forwarded|real-ip|^input$/i';

    /**
     * Applied in order to every string, after the failed queries' bindings and the configured
     * secret values.
     *
     * @var array<string, string>
     */
    private const array PATTERNS = [
        // Webhook signing secrets.
        '/whsec_[A-Za-z0-9+\/=_-]+/' => self::FILTERED,
        // A bearer token, however it is quoted.
        '/\bBearer\s+(?!\[Filtered\])[A-Za-z0-9._~+\/=-]+/' => 'Bearer ' . self::FILTERED,
        // Any other Authorization value, with or without its scheme.
        '/\b(authorization["\']?\s*(?:=>|[:=])\s*["\']?(?:(?:Bearer|Basic|Digest|Token)\s+)?+)(?!\[Filtered\])[^\s"\',;}\]]+/i' => '$1' . self::FILTERED,
        // Email addresses.
        '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i' => self::FILTERED,
        // PostgreSQL's DETAIL line repeats a failing row's or key's values.
        '/(\bDETAIL:\s*)\S.*?(?=\s\(Connection: |\R|$)/s' => '$1' . self::FILTERED,
    ];

    /**
     * @var list<string>
     */
    private array $secrets;

    /**
     * @param  list<string>  $secrets  exact values that are never sent
     */
    public function __construct(array $secrets)
    {
        $this->secrets = array_values(array_filter($secrets, fn (string $secret): bool => mb_strlen($secret) >= self::MIN_SECRET_LENGTH));
    }

    /**
     * The `before_send` and `before_send_transaction` callable in `config/sentry.php`: a
     * static method, because `config:cache` cannot store a closure or an object.
     */
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        return self::fromConfig()($event, $hint);
    }

    /**
     * The secrets production holds in `shared/.env`.
     */
    public static function fromConfig(): self
    {
        $values = [
            config('pixelforge.api_key'),
            config('pixelforge.webhook_secret'),
            config('app.key'),
            config('database.connections.' . config()->string('database.default') . '.password'),
        ];

        return new self(array_values(array_filter($values, is_string(...))));
    }

    public function __invoke(Event $event, ?EventHint $hint = null): Event
    {
        $replacements = $this->replacements($event, $hint);

        $this->redactMessage($event, $replacements);
        $this->redactExceptions($event, $replacements);
        $this->redactBreadcrumbs($event, $replacements);
        $this->redactRequest($event, $replacements);
        $this->redactUser($event);
        $this->redactSpans($event, $replacements);

        $event->setExtra($this->redactArray($event->getExtra(), $replacements, 0));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, $this->redactArray($context, $replacements, 0));
        }

        $tags = [];
        foreach ($event->getTags() as $key => $value) {
            $tags[$key] = $this->isSensitiveKey($key) ? self::FILTERED : $this->redactString($value, $replacements);
        }
        $event->setTags($tags);

        if ($event->getTransaction() !== null) {
            $event->setTransaction($this->redactString($event->getTransaction(), $replacements));
        }

        if ($event->getStacktrace() !== null) {
            $this->dropFrameVariables($event->getStacktrace());
        }

        return $event;
    }

    /**
     * Exact replacements: a failed query's message with its bindings becomes its SQL with
     * placeholders, wherever it appears, and every configured secret is filtered.
     *
     * @return array<string, string>
     */
    private function replacements(Event $event, ?EventHint $hint): array
    {
        $throwables = $hint?->exception === null ? [] : [$hint->exception];

        foreach ($event->getBreadcrumbs() as $breadcrumb) {
            $throwables = [...$throwables, ...$this->throwablesIn($breadcrumb->getMetadata(), 0)];
        }

        $throwables = [...$throwables, ...$this->throwablesIn($event->getExtra(), 0)];

        $replacements = [];

        foreach ($throwables as $throwable) {
            for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
                if (! $current instanceof QueryException || $current->getBindings() === []) {
                    continue;
                }

                $withBindings = Str::replaceArray('?', $current->getBindings(), $current->getSql());

                if ($withBindings !== $current->getSql()) {
                    $replacements[$withBindings] = $current->getSql();
                }
            }
        }

        foreach ($this->secrets as $secret) {
            $replacements[$secret] = self::FILTERED;
        }

        return $replacements;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<Throwable>
     */
    private function throwablesIn(array $data, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $throwables = [];

        foreach ($data as $value) {
            if ($value instanceof Throwable) {
                $throwables[] = $value;
            } elseif (is_array($value)) {
                $throwables = [...$throwables, ...$this->throwablesIn($value, $depth + 1)];
            }
        }

        return $throwables;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function redactMessage(Event $event, array $replacements): void
    {
        $message = $event->getMessage();

        if ($message === null) {
            return;
        }

        $formatted = $event->getMessageFormatted();

        $event->setMessage(
            $this->redactString($message, $replacements),
            array_map(fn (string $parameter): string => $this->redactString($parameter, $replacements), $event->getMessageParams()),
            $formatted === null ? null : $this->redactString($formatted, $replacements),
        );
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function redactExceptions(Event $event, array $replacements): void
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($this->redactString($exception->getValue(), $replacements));

            if ($exception->getStacktrace() !== null) {
                $this->dropFrameVariables($exception->getStacktrace());
            }
        }
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function redactBreadcrumbs(Event $event, array $replacements): void
    {
        $event->setBreadcrumb(array_map(fn (Breadcrumb $breadcrumb): Breadcrumb => new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $breadcrumb->getMessage() === null ? null : $this->redactString($breadcrumb->getMessage(), $replacements),
            $this->redactArray($breadcrumb->getMetadata(), $replacements, 0),
            $breadcrumb->getTimestamp(),
        ), $event->getBreadcrumbs()));
    }

    /**
     * The body, cookies and server variables are never sent, whatever collected them.
     *
     * @param  array<string, string>  $replacements
     */
    private function redactRequest(Event $event, array $replacements): void
    {
        $request = $event->getRequest();

        unset($request['data'], $request['cookies'], $request['env']);

        $event->setRequest($this->redactArray($request, $replacements, 0));
    }

    /**
     * Only the id stays: never an email, an IP address or a username.
     */
    private function redactUser(Event $event): void
    {
        $id = $event->getUser()?->getId();

        $event->setUser($id === null ? null : UserDataBag::createFromUserIdentifier($id));
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function redactSpans(Event $event, array $replacements): void
    {
        foreach ($event->getSpans() as $span) {
            if ($span->getDescription() !== null) {
                $span->setDescription($this->redactString($span->getDescription(), $replacements));
            }

            /** @var array<string, mixed> $data */
            $data = $span->getData();
            $span->setData($this->redactArray($data, $replacements, 0));
        }
    }

    /**
     * Function arguments can hold a password or a prompt that no pattern recognises.
     */
    private function dropFrameVariables(Stacktrace $stacktrace): void
    {
        foreach ($stacktrace->getFrames() as $frame) {
            $frame->setVars([]);
        }
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @param  array<string, string>  $replacements
     * @return array<TKey, mixed>
     */
    private function redactArray(array $data, array $replacements, int $depth): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $redacted[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? $this->filterAll($value, $depth + 1)
                : $this->redactValue($value, $replacements, $depth + 1);
        }

        return $redacted;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function redactValue(mixed $value, array $replacements, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::FILTERED;
        }

        return match (true) {
            is_string($value) => $this->redactString($value, $replacements),
            is_array($value) => $this->redactArray($value, $replacements, $depth),
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            $value instanceof Throwable => $value::class,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof JsonSerializable => $this->redactValue($value->jsonSerialize(), $replacements, $depth + 1),
            $value instanceof Stringable => $this->redactString((string) $value, $replacements),
            is_object($value) => $value::class,
            default => self::FILTERED,
        };
    }

    /**
     * Filters every value and keeps the shape, so a header stays a list of values.
     */
    private function filterAll(mixed $value, int $depth): mixed
    {
        if (is_array($value) && $depth <= self::MAX_DEPTH) {
            return array_map(fn (mixed $item): mixed => $this->filterAll($item, $depth + 1), $value);
        }

        return $value === null ? null : self::FILTERED;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function redactString(string $value, array $replacements): string
    {
        $value = strtr($value, $replacements);

        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value) ?? self::FILTERED;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1;
    }
}
