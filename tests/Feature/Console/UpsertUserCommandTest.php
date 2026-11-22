<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * `app:upsert-user` creates or updates the reviewer account over SSH. The password is the
 * first line of standard input, so it never reaches argv or a shell history (D-23).
 */
final class UpsertUserCommandTest extends TestCase
{
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    private const string PASSWORD = 'first-Secret-4711';

    /**
     * @var list<MessageLogged>
     */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $message): void {
            $this->loggedMessages[] = $message;
        });
    }

    public function test_it_creates_a_user_with_a_trimmed_lowercased_email(): void
    {
        [$exitCode, $output] = $this->upsert('  Ada@Example.COM ', ' Ada Lovelace ', [self::PASSWORD]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Created user ada@example.com', $output);

        $user = User::query()->sole();
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $this->assertPasswordNeverLeaked(self::PASSWORD, $output);
        $this->assertOneInfoLineFor($user);
    }

    public function test_running_it_again_updates_the_same_user(): void
    {
        $this->upsert('ada@example.com', 'Ada Lovelace', [self::PASSWORD]);
        $this->loggedMessages = [];

        [$exitCode, $output] = $this->upsert('ADA@example.com', 'Ada King', ['second-Secret-0815']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Updated user ada@example.com', $output);

        $user = User::query()->sole();
        $this->assertSame('Ada King', $user->name);
        $this->assertTrue(Hash::check('second-Secret-0815', $user->password));
        $this->assertFalse(Hash::check(self::PASSWORD, $user->password));

        $this->assertPasswordNeverLeaked('second-Secret-0815', $output);
        $this->assertOneInfoLineFor($user);
    }

    /**
     * AC 2: the account it creates gets its starter grant.
     */
    public function test_creating_a_user_grants_the_starter_credit_once(): void
    {
        $this->upsert('ada@example.com', 'Ada Lovelace', [self::PASSWORD]);
        $this->upsert('ada@example.com', 'Ada King', ['second-Secret-0815']);

        $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor(User::query()->sole()));
    }

    /**
     * Re-running it for an existing user grants nothing, even to one without a grant:
     * the opening-grant backfill covers those.
     */
    public function test_updating_a_user_grants_nothing(): void
    {
        $existing = User::factory()->withoutCredits()->create(['email' => 'ada@example.com']);

        [$exitCode] = $this->upsert('ada@example.com', 'Ada King', [self::PASSWORD]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([], $this->topUpsFor($existing));
    }

    public function test_a_failing_grant_creates_no_user(): void
    {
        CreditLedgerEntry::created(function (): void {
            throw new RuntimeException('The grant write failed.');
        });

        try {
            $this->upsert('ada@example.com', 'Ada Lovelace', [self::PASSWORD]);
            $this->fail('The upsert did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The grant write failed.', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
    }

    /**
     * @param  list<string>  $stdin
     */
    #[DataProvider('rejectedInput')]
    public function test_invalid_input_is_rejected_without_writing_anything(string $email, string $name, array $stdin, string $message): void
    {
        [$exitCode, $output] = $this->upsert($email, $name, $stdin);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($message, $output);
        $this->assertSame(0, User::query()->count());

        if ($stdin !== []) {
            $this->assertPasswordNeverLeaked($stdin[0], $output);
        }
    }

    /**
     * @return array<string, array{string, string, list<string>, string}>
     */
    public static function rejectedInput(): array
    {
        return [
            'password under 8 characters' => ['ada@example.com', 'Ada', ['Short-7'], 'The password field must be at least 8 characters.'],
            'empty stdin' => ['ada@example.com', 'Ada', [], 'The password field is required.'],
            'invalid email' => ['not-an-email', 'Ada', [self::PASSWORD], 'The email field must be a valid email address.'],
            'name over 255 characters' => ['ada@example.com', str_repeat('a', 256), [self::PASSWORD], 'The name field must not be greater than 255 characters.'],
        ];
    }

    public function test_a_short_password_leaves_an_existing_user_unchanged(): void
    {
        $this->upsert('ada@example.com', 'Ada Lovelace', [self::PASSWORD]);
        $before = User::query()->sole();

        [$exitCode, $output] = $this->upsert('ada@example.com', 'Ada King', ['Short-7']);

        $this->assertSame(1, $exitCode);
        $after = User::query()->sole();
        $this->assertSame('Ada Lovelace', $after->name);
        $this->assertSame($before->password, $after->password);
        $this->assertPasswordNeverLeaked('Short-7', $output);
    }

    public function test_a_password_of_exactly_8_characters_is_accepted(): void
    {
        [$exitCode, $output] = $this->upsert('ada@example.com', 'Ada', ['Exact-8!']);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Hash::check('Exact-8!', User::query()->sole()->password));
        $this->assertPasswordNeverLeaked('Exact-8!', $output);
    }

    public function test_only_the_line_ending_is_stripped_from_the_password(): void
    {
        [$exitCode, $output] = $this->upsert('ada@example.com', 'Ada', ["pass word \r"]);

        $this->assertSame(0, $exitCode);
        $password = User::query()->sole()->password;
        $this->assertTrue(Hash::check('pass word ', $password));
        $this->assertFalse(Hash::check('pass word', $password));
        $this->assertPasswordNeverLeaked('pass word ', $output);
    }

    /**
     * Run from a terminal without a pipe, `fgets` would wait without a prompt and echo the
     * password as it is typed, so the command refuses instead. A pseudo-terminal stands in for
     * the operator's TTY.
     */
    public function test_it_refuses_to_read_the_password_from_a_terminal(): void
    {
        $terminal = @fopen('/dev/ptmx', 'r+');
        $this->assertNotFalse($terminal, 'Precondition: this test needs /dev/ptmx for a pseudo-terminal.');
        $this->assertTrue(stream_isatty($terminal), 'Precondition: /dev/ptmx must open as a TTY.');

        $input = new ArrayInput(['email' => 'ada@example.com', 'name' => 'Ada']);
        $input->setStream($terminal);
        $output = new BufferedOutput;

        $exitCode = Artisan::all()['app:upsert-user']->run($input, $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Pipe the password on stdin instead of typing it (docs/production.md, "Reviewer account").', $output->fetch());
        $this->assertSame(0, User::query()->count());
        $this->assertSame([], $this->loggedMessages);
    }

    /**
     * `setInputs()` writes each entry followed by PHP_EOL, so `["x"]` is the stdin line `x\n`.
     *
     * @param  list<string>  $stdin
     * @return array{int, string}
     */
    private function upsert(string $email, string $name, array $stdin): array
    {
        $tester = new CommandTester(Artisan::all()['app:upsert-user']);
        $tester->setInputs($stdin);

        $exitCode = $tester->execute(['email' => $email, 'name' => $name]);

        return [$exitCode, $tester->getDisplay()];
    }

    private function assertPasswordNeverLeaked(string $password, string $output): void
    {
        $this->assertStringNotContainsString($password, $output);

        foreach ($this->loggedMessages as $logged) {
            $this->assertStringNotContainsString($password, $logged->message);
            $this->assertStringNotContainsString($password, (string) json_encode($logged->context));
        }
    }

    private function assertOneInfoLineFor(User $user): void
    {
        $this->assertCount(1, $this->loggedMessages);
        $this->assertSame('info', $this->loggedMessages[0]->level);
        $this->assertSame(['email' => $user->email, 'user_id' => $user->id], $this->loggedMessages[0]->context);
    }
}
