<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AidRequest;
use App\Models\BeneficiaryAttendance;

class UpdateColaAmounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cola:update-amounts {--beneficiary_id= : Update only for specific beneficiary}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update COLA amounts for pending requests based on latest attendance data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $beneficiaryId = $this->option('beneficiary_id');
        
        if ($beneficiaryId) {
            $this->info("Updating COLA amounts for beneficiary ID: {$beneficiaryId}");
            $updatedCount = AidRequest::recalculateColaAmounts($beneficiaryId);
        } else {
            $this->info('Updating COLA amounts for all pending requests...');
            
            // Get all beneficiaries with pending COLA requests
            $beneficiariesWithCola = AidRequest::where('fund_type', 'cola')
                ->where('status', 'pending')
                ->distinct()
                ->pluck('beneficiary_id');
            
            $totalUpdated = 0;
            $progressBar = $this->output->createProgressBar($beneficiariesWithCola->count());
            
            foreach ($beneficiariesWithCola as $benId) {
                $updated = AidRequest::recalculateColaAmounts($benId);
                $totalUpdated += $updated;
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->newLine();
            $updatedCount = $totalUpdated;
        }
        
        $this->info("Successfully updated {$updatedCount} COLA request(s).");
        
        return 0;
    }
}
