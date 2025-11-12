<?php

namespace App\Console\Commands;

use App\Services\FreeTrialService;
use Illuminate\Console\Command;

class ExpireFreeTrials extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'subscriptions:expire-free-trials {--dry-run : Show what would be expired without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Expire free trials that have exceeded their 30-second duration. Runs without webhook dependency.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Checking for expired free trials...');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        
        $freeTrialService = new FreeTrialService();
        
        if ($dryRun) {
            // For dry run, we'll need to implement a preview method
            $this->info('Dry run not yet implemented. Remove --dry-run to execute.');
            return Command::SUCCESS;
        }
        
        $expiredCount = $freeTrialService->expireElapsedFreeTrials();
        
        if ($expiredCount > 0) {
            $this->info("Expired {$expiredCount} free trial(s)");
        } else {
            $this->info('No free trials needed expiration');
        }
        
        return Command::SUCCESS;
    }
}