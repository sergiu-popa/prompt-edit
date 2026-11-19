<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Creates or updates a user by email, for the reviewer account on production. The password is
 * the first line of standard input, never an argument, so other users of the droplet can't
 * read it from argv and it never lands in a shell history (D-23). It is validated like a
 * registration, and it never appears in the output or the logs.
 */
#[Signature('app:upsert-user {email : The account email (trimmed and lowercased)} {name : The display name}')]
#[Description('Create or update a user by email; the password is the first line of stdin')]
final class UpsertUserCommand extends Command
{
    public function handle(): int
    {
        $passwordSource = $this->passwordSource();

        if (stream_isatty($passwordSource)) {
            $this->components->error('Pipe the password on stdin instead of typing it (README, "Reviewer account").');

            return self::FAILURE;
        }

        $email = Str::lower(trim($this->stringArgument('email')));
        $name = trim($this->stringArgument('name'));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $this->firstLineOf($passwordSource)],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $validator->validated();

        $user = $this->upsert($validated['email'], $validated['name'], $validated['password']);

        $action = $user->wasRecentlyCreated ? 'created' : 'updated';

        $this->components->info(ucfirst($action) . " user {$user->email}.");
        Log::info("User {$action} by app:upsert-user", ['email' => $user->email, 'user_id' => $user->id]);

        return self::SUCCESS;
    }

    private function upsert(string $email, string $name, #[SensitiveParameter] string $password): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password],
        );
    }

    /**
     * The input stream (set by tests) or STDIN. On a terminal, reading it would wait without a
     * prompt and echo the password as it is typed, so `handle()` refuses a TTY.
     *
     * @return resource
     */
    private function passwordSource(): mixed
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        return $stream ?? STDIN;
    }

    /**
     * The first line of the stream, with only its line ending stripped: registration doesn't
     * trim passwords either.
     *
     * @param  resource  $stream
     */
    private function firstLineOf(mixed $stream): string
    {
        $line = fgets($stream);

        if ($line === false) {
            return '';
        }

        return (string) preg_replace('/\r?\n\z/', '', $line);
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
