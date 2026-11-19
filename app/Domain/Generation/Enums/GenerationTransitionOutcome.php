<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

/**
 * What the transition action did with a change, valued in snake_case for logs.
 * Only `Applied` writes anything.
 */
enum GenerationTransitionOutcome: string
{
    case Applied = 'applied';

    /**
     * The row is already in this status.
     */
    case IgnoredDuplicate = 'ignored_duplicate';

    /**
     * The change is behind the row: a lower rank arriving late.
     */
    case IgnoredStale = 'ignored_stale';

    /**
     * The row is succeeded or failed, and the change is not.
     */
    case IgnoredTerminal = 'ignored_terminal';

    /**
     * A terminal row receiving the opposite terminal status.
     */
    case IgnoredConflict = 'ignored_conflict';

    /**
     * A snapshot of another generation, or a local failure on a row Pixelforge accepted.
     */
    case RefusedConflict = 'refused_conflict';
}
