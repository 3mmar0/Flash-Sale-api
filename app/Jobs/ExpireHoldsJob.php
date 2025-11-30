<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Services\HoldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(HoldService $holdService): void
    {
        $expiredCount = 0;

        // Process expired holds in batches to prevent memory issues
        Hold::active()
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($holds) use ($holdService, &$expiredCount) {
                foreach ($holds as $hold) {
                    // Use database lock to prevent double-processing
                    $lockedHold = Hold::lockForUpdate()->find($hold->id);

                    if ($lockedHold && $lockedHold->status === 'active') {
                        try {
                            $holdService->expireHold($lockedHold);
                            $expiredCount++;
                        } catch (\Exception $e) {
                            Log::error('Failed to expire hold', [
                                'hold_id' => $hold->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            });

        if ($expiredCount > 0) {
            Log::info('Expired holds processed', [
                'count' => $expiredCount,
            ]);
        }
    }
}

