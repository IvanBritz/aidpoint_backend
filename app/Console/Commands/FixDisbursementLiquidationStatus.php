<?php

namespace App\Console\Commands;

use App\Models\Disbursement;
use App\Models\Liquidation;
use Illuminate\Console\Command;

class FixDisbursementLiquidationStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'liquidation:fix-disbursement-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix disbursement liquidation status for all disbursements with approved liquidations';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Fixing disbursement liquidation status...');

        // Find all approved liquidations
        $approvedLiquidations = Liquidation::where('status', 'approved')->get();
        $this->info("Found {$approvedLiquidations->count()} approved liquidations");

        // Group liquidations by disbursement to avoid multiple updates
        $disbursementIds = $approvedLiquidations->pluck('disbursement_id')->unique();
        $this->info("Updating {$disbursementIds->count()} disbursements");

        $updated = 0;
        $fullyLiquidated = 0;

        foreach ($disbursementIds as $disbursementId) {
            $disbursement = Disbursement::find($disbursementId);
            if ($disbursement) {
                $beforeFullyLiquidated = $disbursement->fully_liquidated;
                
                // Update liquidation status
                $disbursement->updateLiquidationStatus();
                
                $updated++;
                if ($disbursement->fully_liquidated && !$beforeFullyLiquidated) {
                    $fullyLiquidated++;
                    $this->line("  Disbursement #{$disbursementId} marked as fully liquidated");
                }
            }
        }

        $this->info("Updated {$updated} disbursements");
        $this->info("Marked {$fullyLiquidated} disbursements as fully liquidated");
        $this->info('Fix complete!');

        return Command::SUCCESS;
    }
}