<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\ReconcilerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Whether the scheduled reconciler is running (D-59): 200 `ok` while its last
 * completed run is at most 5 min old, 503 `stale` otherwise, including before the
 * first run. It shows that instant and nothing more.
 */
final class ShowReconcilerHealthController extends Controller
{
    public function __invoke(ReconcilerHeartbeat $heartbeat): JsonResponse
    {
        $isStale = $heartbeat->isStale(CarbonImmutable::now());

        return response()->json([
            'status' => $isStale ? 'stale' : 'ok',
            'last_succeeded_at' => $heartbeat->lastSuccessfulRunAt()?->toIso8601ZuluString(),
        ], $isStale ? 503 : 200, ['Cache-Control' => 'no-store']);
    }
}
