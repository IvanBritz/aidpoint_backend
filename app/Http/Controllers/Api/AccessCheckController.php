<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAidSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AccessSuspensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AccessCheckController extends Controller
{
    /**
     * Client-triggered check for zero remaining time, auto-suspend if needed.
     * Returns all available plans for renewal on suspension.
     */
    public function checkExpirationAndSuspend(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Get current active subscription
        $subscription = FinancialAidSubscription::with('subscriptionPlan')
            ->where('user_id', $user->id)
            ->where('status', 'Active')
            ->first();

        if (!$subscription) {
            // No active subscription, return renewal plans
            return $this->getRenewalResponse($user, 'No active subscription');
        }

        $plan = $subscription->subscriptionPlan;
        $now = now();

        // Check if subscription has expired
        $isExpired = false;
        
        // For free trials with seconds
        if ($plan && ($plan->duration_in_seconds > 0 || ($plan->is_free_trial && $plan->trial_seconds > 0))) {
            $startTime = new \DateTime($subscription->created_at ?: $subscription->start_date);
            $elapsedSeconds = $now->getTimestamp() - $startTime->getTimestamp();
            $window = (int)($plan->duration_in_seconds ?? 0);
            if ($window <= 0) { $window = (int)($plan->trial_seconds ?? 0); }
            $isExpired = $elapsedSeconds >= $window;
        } 
        // For regular plans with end_date
        else {
            $endOfDay = new \DateTime($subscription->end_date . ' 23:59:59');
            $isExpired = $now >= $endOfDay;
        }
        
        // Also check for seconds-based plans (inferred from name)
        if (!$isExpired && $plan) {
            $planText = ($plan->plan_name ?? '') . ' ' . ($plan->description ?? '');
            if (preg_match('/(\d+)\s*second(s)?/i', $planText, $matches)) {
                $planSeconds = (int)$matches[1];
                if ($planSeconds > 0) {
                    $startTime = new \DateTime($subscription->created_at ?: $subscription->start_date);
                    $elapsedSeconds = $now->getTimestamp() - $startTime->getTimestamp();
                    $isExpired = $elapsedSeconds >= $planSeconds;
                }
            }
        }

        if ($isExpired) {
            try {
                // Mark subscription as expired
                $subscription->update(['status' => 'Expired']);

                // Suspend access (archive users, revoke tokens, notify)
                $suspensionService = new AccessSuspensionService();
                $suspensionService->suspendCenterAccessForDirector($user, 'Subscription expired - access duration reached 0');

                Log::info('Subscription expired and access suspended', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->subscription_id,
                    'plan_name' => $plan->plan_name ?? 'Unknown'
                ]);

                return $this->getRenewalResponse($user, 'Subscription expired. Access suspended. Please renew to regain access.');

            } catch (\Throwable $e) {
                Log::error('Failed to suspend access on expiration', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                
                return response()->json([
                    'expired' => true,
                    'suspended' => false,
                    'error' => 'Failed to suspend access automatically',
                    'message' => 'Subscription expired but suspension failed. Please contact support.'
                ], 500);
            }
        }

        return response()->json([
            'expired' => false,
            'suspended' => false,
            'message' => 'Subscription is still active'
        ]);
    }

    private function getRenewalResponse(User $user, string $message): JsonResponse
    {
        // Get available renewal plans (Basic, Premium, etc - exclude free/trial plans)
        $renewalPlans = SubscriptionPlan::where('archived', false)
            ->where(function($query) {
                $query->where('is_free_trial', false)
                      ->orWhereNull('is_free_trial');
            })
            ->whereNotIn('plan_name', ['Free', 'free'])
            ->orderBy('price', 'asc')
            ->get();

        return response()->json([
            'expired' => true,
            'suspended' => true,
            'message' => $message,
            'renewal_required' => true,
            'available_plans' => $renewalPlans,
            'user_status' => 'archived'
        ]);
    }
}