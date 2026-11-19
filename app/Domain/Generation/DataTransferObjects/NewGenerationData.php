<?php

declare(strict_types=1);

namespace App\Domain\Generation\DataTransferObjects;

use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;

/**
 * A generation request exactly as the user gave it. It carries no price:
 * the estimate is always recomputed on the server.
 */
final readonly class NewGenerationData
{
    /**
     * @param  list<string>  $referenceImages  in the order given
     * @param  ?string  $submissionToken  the Generate form submission that sent it, if any (D-38)
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
        public ?string $submissionToken = null,
    ) {}
}
