<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAid;
use App\Models\FinancialAidSubscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Return admin dashboard data: list of centers and subscription earnings
     */
    public function dashboard(): JsonResponse
    {
        $user = Auth::user();

        // Basic authorization: only admins (systemrole_id == 1) may access
        if (!$user || (int)($user->systemrole_id) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden'
            ], 403);
        }

        // Centers list with detailed information
        $centers = FinancialAid::with([
            'owner:id,firstname,middlename,lastname,email'
        ])
        ->orderByDesc('created_at')
        ->get(['id','user_id','center_id','center_name','isManagable','created_at'])
        ->map(function ($center) {
            // Get current active subscription for the center owner
            $currentSubscription = FinancialAidSubscription::with('subscriptionPlan')
                ->where('user_id', $center->user_id)
                ->where('status', 'Active')
                ->where('end_date', '>=', now()->toDateString())
                ->first();
            
            // Get beneficiary count for this center
            $beneficiaryCount = User::where('financial_aid_id', $center->id)
                ->where('systemrole_id', 6) // Beneficiary role ID is 6
                ->count();
            
            // Get pending subscription requests for this center owner
            $pendingRequests = FinancialAidSubscription::where('user_id', $center->user_id)
                ->where('status', 'Pending')
                ->count();
            
            // Get total earnings from this center's subscriptions
            $totalEarnings = SubscriptionTransaction::where('user_id', $center->user_id)
                ->sum('amount_paid');
            
            return [
                'id' => $center->id,
                'center_id' => $center->center_id,
                'center_name' => $center->center_name,
                'isManagable' => $center->isManagable,
                'created_at' => $center->created_at,
                'owner' => $center->owner,
                'current_plan' => $currentSubscription ? [
                    'name' => $currentSubscription->subscriptionPlan->plan_name,
                    'price' => $currentSubscription->subscriptionPlan->price,
                    'duration' => $currentSubscription->subscriptionPlan->duration_months,
                    'status' => $currentSubscription->status,
                    'end_date' => $currentSubscription->end_date,
                ] : null,
                'beneficiary_count' => $beneficiaryCount,
                'pending_subscription_requests' => $pendingRequests,
                'total_earnings' => (float) $totalEarnings,
            ];
        });

        // Earnings from subscription transactions
        $totalEarnings = (float) SubscriptionTransaction::sum('amount_paid');
        $totalTransactions = (int) SubscriptionTransaction::count();

        // Some helpful counts for the UI
        $totalCenters = (int) $centers->count();
        $approvedCenters = (int) $centers->where('isManagable', true)->count();
        $pendingCenters = $totalCenters - $approvedCenters;

        // Active subscribers (distinct users with active subs)
        $activeSubscribers = (int) FinancialAidSubscription::where('status', 'Active')
            ->where('end_date', '>=', now()->toDateString())
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'success' => true,
            'data' => [
                'centers' => $centers,
                'metrics' => [
                    'total_earnings' => $totalEarnings,
                    'total_transactions' => $totalTransactions,
                    'total_centers' => $totalCenters,
                    'approved_centers' => $approvedCenters,
                    'pending_centers' => $pendingCenters,
                    'active_subscribers' => $activeSubscribers,
                ],
            ],
            'message' => 'Admin dashboard data retrieved successfully.'
        ]);
    }
}
