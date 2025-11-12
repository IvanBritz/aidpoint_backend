<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SubscriptionPlan;

class CleanSubscriptionPlans extends Command
{
    protected $signature = 'plans:clean {--force : Run without confirmation}';
    protected $description = 'Delete all subscription plans except Free, Basic, Premium, Test, and 30segundo';

    public function handle()
    {
        $keepPlans = ['Free', 'Basic', 'Premium', 'Test', '30segundo'];
        
        $plansToDelete = SubscriptionPlan::whereNotIn('plan_name', $keepPlans)->get();
        
        $this->info('Plans to be deleted:');
        foreach ($plansToDelete as $plan) {
            $hasSubscriptions = $plan->financialAidSubscriptions()->count() > 0;
            $hasTransactions = $plan->newTransactions()->count() > 0 || $plan->oldTransactions()->count() > 0;
            
            $status = '';
            if ($hasSubscriptions) {
                $status .= ' (has subscriptions)';
            }
            if ($hasTransactions) {
                $status .= ' (has transactions)';
            }
            
            $this->line("- {$plan->plan_name} (ID: {$plan->plan_id}){$status}");
        }
        
        if ($plansToDelete->isEmpty()) {
            $this->info('No plans to delete.');
            return 0;
        }
        
        if ($this->option('force') || $this->confirm('Do you want to proceed with deleting these plans?')) {
            $deletedCount = 0;
            $skippedCount = 0;
            
            foreach ($plansToDelete as $plan) {
                // Check for foreign key constraints
                $hasSubscriptions = $plan->financialAidSubscriptions()->count() > 0;
                $hasTransactions = $plan->newTransactions()->count() > 0 || $plan->oldTransactions()->count() > 0;
                
                if ($hasSubscriptions || $hasTransactions) {
                    $this->warn("Skipping {$plan->plan_name} - has foreign key references");
                    $skippedCount++;
                    continue;
                }
                
                try {
                    $plan->delete();
                    $deletedCount++;
                    $this->line("Deleted: {$plan->plan_name}");
                } catch (\Exception $e) {
                    $this->error("Failed to delete {$plan->plan_name}: " . $e->getMessage());
                    $skippedCount++;
                }
            }
            
            $this->info("Completed: deleted {$deletedCount}, skipped {$skippedCount} subscription plan(s).");
            return 0;
        }

        $this->info('Operation cancelled.');
        return 1;
    }
}
