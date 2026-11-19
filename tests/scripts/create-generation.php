<?php

/**
 * A second process for CreateGenerationActionConcurrencyTest, with its own database
 * connection: it creates the docs' Lighthouse generation for the user whose id is the
 * first argument, under the form submission token in the second, and prints the id it
 * got back, whether it created that row, and how many submit jobs it pushed, as JSON.
 * The queue is faked, so nothing is ever sent to Pixelforge. Not a PHPUnit file.
 */

declare(strict_types=1);

use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Queue;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = $argv[1] ?? throw new InvalidArgumentException('Pass the user id as the first argument.');
$submissionToken = $argv[2] ?? throw new InvalidArgumentException('Pass the submission token as the second argument.');

$queue = Queue::fake([SubmitGenerationJob::class]);

$generation = $app->make(CreateGenerationAction::class)->execute(
    User::query()->findOrFail((int) $userId),
    new NewGenerationData(
        'A lighthouse on a cliff at dawn, oil painting',
        ImageSize::Landscape,
        ImageQuality::High,
        ['https://example.com/style.png'],
        $submissionToken,
    ),
);

echo json_encode([
    'id' => $generation->id,
    'was_recently_created' => $generation->wasRecentlyCreated,
    'pushed_jobs' => $queue->pushed(SubmitGenerationJob::class)->count(),
], JSON_THROW_ON_ERROR);
