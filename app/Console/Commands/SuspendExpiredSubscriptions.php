<?php

namespace App\Console\Commands;

use App\Models\FinancialAidSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AccessSuspensionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SuspendExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:suspend-expired {--dry-run}';
    protected $description = 'Suspend access and archive data for subscriptions that have ended (any plan).';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $dry = (bool) $this->option('dry-run');

        $expired = FinancialAidSubscription::with('subscriptionPlan')
            ->where('status', 'Active')
            ->whereDate('end_date', '<', $today)
            ->get();

        $this->info('Found ' . $expired->count() . ' active subscriptions past end_date.');

        $service = new AccessSuspensionService();

        foreach ($expired as $sub) {
            $user = User::find($sub->user_id);
            if (!$user) { continue; }

            $plan = $sub->subscriptionPlan;

            if ($dry) {
                $this->line("[DRY-RUN] Would suspend user {$user->id} (plan: " . ($plan->plan_name ?? 'n/a') . ")");
                continue;
            }

            // Mark subscription as expired
            $sub->update(['status' => 'Expired']);

            // Suspend center access (archive data + revoke tokens)
            $service->suspendCenterAccessForDirector($user, 'Subscription ended');

            $this->info("Suspended access for user {$user->id}");
        }

        return self::SUCCESS;
    }
}
