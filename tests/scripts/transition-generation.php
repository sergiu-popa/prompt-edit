<?php

/**
 * A second process for TransitionGenerationActionConcurrencyTest, with its own
 * database connection: it applies the generation object JSON in the second
 * argument to the row whose ULID is the first, and prints the outcome and how
 * many UPDATEs it issued on `generations`, as JSON. Not a PHPUnit file.
 *
 * The object is an argument, not stdin: the parent only feeds a child's stdin
 * while it waits on it, and it must first see both children wait on the lock.
 */

declare(strict_types=1);

use App\Domain\Generation\Actions\TransitionGenerationAction;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$generationId = $argv[1] ?? throw new InvalidArgumentException('Pass the generation ULID as the first argument.');
$object = json_decode($argv[2] ?? '', associative: true, flags: JSON_THROW_ON_ERROR);

if (! is_array($object)) {
    throw new InvalidArgumentException('Pass the generation object JSON as the second argument.');
}

$generationUpdates = 0;

DB::listen(function (QueryExecuted $query) use (&$generationUpdates): void {
    if (preg_match('/^update "generations"/i', $query->sql) === 1) {
        $generationUpdates++;
    }
});

$outcome = $app->make(TransitionGenerationAction::class)->execute(
    Generation::query()->whereKey($generationId)->firstOrFail(),
    GenerationSnapshot::from($object),
);

echo json_encode(['outcome' => $outcome->value, 'generation_updates' => $generationUpdates], JSON_THROW_ON_ERROR);
