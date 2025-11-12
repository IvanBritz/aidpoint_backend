<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Services\FreeTrialService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    /**
     * Display a listing of subscription plans.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $plans = SubscriptionPlan::where('archived', false)->orderBy('plan_name')->get();

        // Compute free-trial eligibility when authenticated using FreeTrialService
        if ($user) {
            $freeTrialService = new FreeTrialService();
            $plans->transform(function ($p) use ($user, $freeTrialService) {
                if ($p->is_free_trial) {
                    $p->is_free_trial_eligible = $freeTrialService->isEligibleForFreeTrial($user, $p);
                } else {
                    $p->is_free_trial_eligible = false;
                }
                return $p;
            });
        }
        
        return response()->json([
            'success' => true,
            'data' => $plans,
            'message' => 'Subscription plans retrieved successfully.'
        ]);
    }

    /**
     * Store a newly created subscription plan.
     */
    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            // Remove automatic free trial flag assignment
            // Only set as free trial if explicitly requested through form
            
            // Ensure months/days/seconds are set (0 allowed)
            $payload['duration_in_months'] = (int) ($payload['duration_in_months'] ?? 0);
            $payload['duration_in_days'] = (int) ($payload['duration_in_days'] ?? 0);
            $payload['duration_in_seconds'] = (int) ($payload['duration_in_seconds'] ?? 0);
            
            // Ensure is_free_trial is set to false by default
            if (!array_key_exists('is_free_trial', $payload)) {
                $payload['is_free_trial'] = false;
            }
            
            // Ensure trial_seconds is set to 0 by default
            if (!array_key_exists('trial_seconds', $payload)) {
                $payload['trial_seconds'] = 0;
            }
            
            $plan = SubscriptionPlan::create($payload);
            
            return response()->json([
                'success' => true,
                'data' => $plan,
                'message' => 'Subscription plan created successfully.'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subscription plan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified subscription plan.
     */
    public function show(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $subscriptionPlan,
            'message' => 'Subscription plan retrieved successfully.'
        ]);
    }

    /**
     * Update the specified subscription plan.
     */
    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        try {
            $subscriptionPlan->update($request->validated());
            
            return response()->json([
                'success' => true,
                'data' => $subscriptionPlan->fresh(),
                'message' => 'Subscription plan updated successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update subscription plan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified subscription plan.
     * Uses soft delete (archiving) to preserve referential integrity.
     */
    public function destroy(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        try {
            // Check if there are any subscriptions using this plan
            $subscriptionsCount = $subscriptionPlan->financialAidSubscriptions()->count();
            
            // Check if there are any transactions referencing this plan
            $oldTransactionsCount = $subscriptionPlan->oldTransactions()->count();
            $newTransactionsCount = $subscriptionPlan->newTransactions()->count();
            
            $totalReferences = $subscriptionsCount + $oldTransactionsCount + $newTransactionsCount;
            
            if ($totalReferences > 0) {
                // Soft delete: Archive the plan instead of hard deleting
                $subscriptionPlan->archived = true;
                $subscriptionPlan->save();
                
                $details = [];
                if ($subscriptionsCount > 0) $details[] = "{$subscriptionsCount} subscription(s)";
                if ($oldTransactionsCount > 0) $details[] = "{$oldTransactionsCount} old transaction(s)";
                if ($newTransactionsCount > 0) $details[] = "{$newTransactionsCount} new transaction(s)";
                
                return response()->json([
                    'success' => true,
                    'message' => 'Subscription plan archived successfully. It has ' . implode(', ', $details) . ' and cannot be permanently deleted.'
                ]);
            }
            
            // If no references exist, safe to hard delete
            $subscriptionPlan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Subscription plan deleted successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle foreign key constraint violations
            if ($e->getCode() === '23000') {
                // Fallback: Archive the plan if foreign key constraint error occurs
                try {
                    $subscriptionPlan->archived = true;
                    $subscriptionPlan->save();
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Subscription plan archived successfully due to existing references.'
                    ]);
                } catch (\Exception $archiveError) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete plan: It has associated subscriptions. Archive operation also failed.'
                    ], 422);
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete subscription plan: ' . $e->getMessage()
            ], 500);
        }
    }
}
