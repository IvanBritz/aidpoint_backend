<?php

namespace App\Services;

use App\Models\FinancialAidSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FreeTrialService
{
    /**
     * Activate a 30-second free trial for directors without any webhook dependency.
     * This service ensures complete server-side activation following the rule:
     * "Don't use webhook for 30 seconds free trials"
     */
    public function activateFreeTrial(User $user, SubscriptionPlan $plan): array
    {
        // Validate director role
        $role = strtolower(optional($user->systemRole)->name);
        if ($role !== 'director') {
            return [
                'success' => false,
                'message' => 'Free trial is available for directors only.'
            ];
        }

        // Validate free trial plan
        if (!$plan->is_free_trial || !($plan->trial_seconds > 0)) {
            return [
                'success' => false,
                'message' => 'Invalid free trial plan.'
            ];
        }

        // Check one-time eligibility
        $alreadyUsed = SubscriptionTransaction::where('user_id', $user->id)
            ->where('new_plan_id', $plan->plan_id)
            ->where('payment_method', 'FREE_TRIAL')
            ->exists();

        if ($alreadyUsed) {
            return [
                'success' => false,
                'message' => 'You have already used the 30-second free trial.'
            ];
        }

        try {
            DB::beginTransaction();

            // Step 1: Expire any existing active subscriptions
            $this->expireActiveSubscriptions($user->id);

            // Step 2: Create active subscription immediately (no webhook needed)
            $subscription = $this->createActiveSubscription($user, $plan);

            // Step 3: Record transaction as paid immediately (single source of truth)
            $this->recordFreeTrialTransaction($user, $plan, $subscription);

            // Step 4: Log the activation for audit purposes
            $this->logFreeTrialActivation($user, $plan);

            DB::commit();

            return [
                'success' => true,
                'subscription' => $subscription->load('subscriptionPlan'),
                'message' => 'Free trial activated immediately. Trial expires in 30 seconds.',
                'expires_at' => $subscription->created_at->addSeconds($plan->trial_seconds),
            ];

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Free trial activation failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->plan_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to activate free trial. Please try again.'
            ];
        }
    }

    /**
     * Check if a user is eligible for the free trial
     */
    public function isEligibleForFreeTrial(User $user, SubscriptionPlan $plan): bool
    {
        // Must be a director
        $role = strtolower(optional($user->systemRole)->name);
        if ($role !== 'director') {
            return false;
        }

        // Must be a valid free trial plan
        if (!$plan->is_free_trial || !($plan->trial_seconds > 0)) {
            return false;
        }

        // Must not have used this trial before
        return !SubscriptionTransaction::where('user_id', $user->id)
            ->where('new_plan_id', $plan->plan_id)
            ->where('payment_method', 'FREE_TRIAL')
            ->exists();
    }

    /**
     * Automatically expire free trials that have exceeded their duration
     */
    public function expireElapsedFreeTrials(): int
    {
        $expiredCount = 0;
        
        $activeTrials = FinancialAidSubscription::with('subscriptionPlan')
            ->where('status', 'Active')
            ->whereHas('subscriptionPlan', function($query) {
                $query->where('is_free_trial', true)
                      ->where('trial_seconds', '>', 0);
            })
            ->get();

        foreach ($activeTrials as $subscription) {
            $plan = $subscription->subscriptionPlan;
            $activatedAt = $subscription->created_at;
            
            if ($activatedAt && $activatedAt->addSeconds($plan->trial_seconds)->lt(now())) {
                $subscription->update(['status' => 'Expired']);
                $expiredCount++;
                
                Log::info('Free trial auto-expired', [
                    'subscription_id' => $subscription->subscription_id,
                    'user_id' => $subscription->user_id,
                    'activated_at' => $activatedAt,
                    'expired_after_seconds' => $plan->trial_seconds
                ]);

                // If access duration has effectively reached 0 seconds, remove the user and associated center users
                try {
                    $user = \App\Models\User::find($subscription->user_id);
                    if ($user) {
                        // Notify the primary user explicitly
                        \App\Models\Notification::notifySubscriptionExpired($user->id, [
                            'plan_name' => $plan->plan_name,
                            'expired_at' => now(),
                        ]);
                        
                        // Suspend center access (archive data and block usage until renewal)
                        (new \App\Services\AccessSuspensionService())->suspendCenterAccessForDirector(
                            $user,
                            'Free trial expired (access duration reached 0 seconds)'
                        );
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to revoke access on free trial expiration', [
                        'subscription_id' => $subscription->subscription_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $expiredCount;
    }

    /**
     * Expire all active subscriptions for a user
     */
    private function expireActiveSubscriptions(int $userId): void
    {
        FinancialAidSubscription::where('user_id', $userId)
            ->where('status', 'Active')
            ->where('end_date', '>=', now()->toDateString())
            ->update(['status' => 'Expired']);
    }

    /**
     * Create an immediately active subscription for the free trial
     */
    private function createActiveSubscription(User $user, SubscriptionPlan $plan): FinancialAidSubscription
    {
        $startDate = now()->toDateString();
        // For free trials, end date is the same as start date since they expire by time, not date
        $endDate = now()->toDateString();

        return FinancialAidSubscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->plan_id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'Active',
        ]);
    }

    /**
     * Record the free trial transaction as paid (single source of truth)
     */
    private function recordFreeTrialTransaction(User $user, SubscriptionPlan $plan, FinancialAidSubscription $subscription): void
    {
        SubscriptionTransaction::create([
            'user_id' => $user->id,
            'old_plan_id' => null,
            'new_plan_id' => $plan->plan_id,
            'payment_method' => 'FREE_TRIAL',
            'amount_paid' => $plan->price, // Usually 0 for free trials
            'transaction_date' => now(),
            'notes' => '30-second director free trial - activated without webhook',
            'status' => 'paid',
            'start_date' => $subscription->start_date,
            'end_date' => $subscription->end_date,
        ]);
    }

    /**
     * Log the free trial activation for audit purposes
     */
    private function logFreeTrialActivation(User $user, SubscriptionPlan $plan): void
    {
        Log::info('Free trial activated without webhook', [
            'user_id' => $user->id,
            'plan_id' => $plan->plan_id,
            'plan_name' => $plan->plan_name,
            'trial_seconds' => $plan->trial_seconds,
            'activated_at' => now(),
            'method' => 'direct_server_activation'
        ]);
    }
}