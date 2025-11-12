<?php

namespace App\Jobs;

use App\Services\FreeTrialService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireFreeTrials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // No parameters needed as this job checks all free trials globally
    }

    /**
     * Execute the job.
     * This job runs periodically to ensure free trials are expired automatically
     * without relying on webhooks, following the business rule.
     */
    public function handle(): void
    {
        Log::info('ExpireFreeTrials job started');
        
        $freeTrialService = new FreeTrialService();
        $expiredCount = $freeTrialService->expireElapsedFreeTrials();
        
        Log::info('ExpireFreeTrials job completed', [
            'expired_trials' => $expiredCount,
            'processed_at' => now()
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExpireFreeTrials job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}