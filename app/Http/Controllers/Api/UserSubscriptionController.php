<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAidSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Services\FreeTrialService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserSubscriptionController extends Controller
{
    /**
     * Get current user's subscriptions
     */
    public function mySubscriptions(): JsonResponse
    {
        $user = Auth::user();
        
        // Use FreeTrialService to auto-expire elapsed free trials
        $freeTrialService = new FreeTrialService();
        $freeTrialService->expireElapsedFreeTrials();

        // Get user subscriptions
        $subscriptions = FinancialAidSubscription::with('subscriptionPlan')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $subscriptions,
            'message' => 'User subscriptions retrieved successfully.'
        ]);
    }

    /**
     * Get current subscription status (lightweight version for polling)
     * For non-directors, report the CENTER (director's) subscription status
     * so staff/beneficiaries are gated consistently by the director's renewal state.
     */
    public function subscriptionStatus(): JsonResponse
    {
        $user = Auth::user();
        
        // Use FreeTrialService to auto-expire elapsed free trials
        $freeTrialService = new FreeTrialService();
        $freeTrialService->expireElapsedFreeTrials();

        $role = strtolower(optional($user->systemRole)->name);
        
        // Admins are exempt from subscription restrictions entirely
        if ($role === 'admin') {
            return response()->json([
                'success' => true,
                'has_active_subscription' => true,
                'current_subscription' => null,
                'checked_at' => now(),
                'exempted' => true,
                'exempted_role' => 'admin'
            ]);
        }

        $queryUserId = $user->id;

        if ($role !== 'director') {
            try {
                if (!empty($user->financial_aid_id)) {
                    $facility = \App\Models\FinancialAid::find($user->financial_aid_id);
                    if ($facility && !empty($facility->user_id)) {
                        $queryUserId = (int) $facility->user_id; // director owner of the facility
                    }
                }
            } catch (\Throwable $e) {
                // fall back to the current user id
                $queryUserId = $user->id;
            }
        }

        // Get only the current active subscription for the chosen user (director for staff; self for director)
        $currentSubscription = FinancialAidSubscription::with('subscriptionPlan')
            ->where('user_id', $queryUserId)
            ->where('status', 'Active')
            ->where('end_date', '>=', now()->toDateString())
            ->orderBy('created_at', 'desc')
            ->first();
        
        $hasActive = $currentSubscription !== null;
        
        return response()->json([
            'success' => true,
            'has_active_subscription' => $hasActive,
            'current_subscription' => $currentSubscription,
            'checked_at' => now()
        ]);
    }

    /**
     * Subscribe to a plan (creates pending subscription)
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plan,plan_id'
        ]);
        
        $user = Auth::user();
        $planId = $request->plan_id;
        
        // Check if user already has a pending subscription
        $existingPending = FinancialAidSubscription::where('user_id', $user->id)
            ->where('status', 'Pending')
            ->first();
            
        if ($existingPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending subscription. Only one pending subscription is allowed.'
            ], 422);
        }
        
        // Check if user has an active subscription
        $hasActiveSubscription = FinancialAidSubscription::where('user_id', $user->id)
            ->where('status', 'Active')
            ->where('end_date', '>=', Carbon::now()->toDateString())
            ->exists();
        
        // Get the plan details
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription plan not found.'
            ], 404);
        }
        
        // Don't allow subscribing to Free plan
        if (strtolower($plan->plan_name) === 'free') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot manually subscribe to the Free plan.'
            ], 422);
        }
        
        try {
            // Get current active subscription for transaction record
            $currentActiveSubscription = FinancialAidSubscription::where('user_id', $user->id)
                ->where('status', 'Active')
                ->where('end_date', '>=', Carbon::now()->toDateString())
                ->first();

            DB::beginTransaction();

            // Always create as Pending; admin must activate
            $status = 'Pending';
            // Record requested start and end period relative to now for reference
            $startDate = Carbon::now()->toDateString();
            $endDate = Carbon::now()->addMonths($plan->duration_in_months)->addDays($plan->duration_in_days)->addSeconds($plan->duration_in_seconds)->toDateString();
            
            $subscription = FinancialAidSubscription::create([
                'user_id' => $user->id,
                'plan_id' => $planId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status
            ]);

            // Create transaction record
            $transactionNotes = 'Subscription request pending admin activation';

            SubscriptionTransaction::create([
                'user_id' => $user->id,
                'old_plan_id' => $currentActiveSubscription ? $currentActiveSubscription->plan_id : null,
                'new_plan_id' => $planId,
                'payment_method' => 'Auto-Submit', // As requested - simple string for now
                'amount_paid' => $plan->price,
                'transaction_date' => Carbon::now(),
                'notes' => $transactionNotes
            ]);

            DB::commit();
            
            $message = 'Subscription request submitted and pending admin activation. Transaction recorded for ₱' . number_format($plan->price, 2) . '.';
            
            return response()->json([
                'success' => true,
                'data' => $subscription->load('subscriptionPlan'),
                'message' => $message
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subscription.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Cancel pending subscription
     */
    public function cancelPendingSubscription(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        $pendingSubscription = FinancialAidSubscription::where('user_id', $user->id)
            ->where('status', 'Pending')
            ->first();
            
        if (!$pendingSubscription) {
            return response()->json([
                'success' => false,
                'message' => 'No pending subscription found.'
            ], 404);
        }
        
        try {
            $pendingSubscription->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Pending subscription cancelled successfully.'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Avail the one-time 30-second free trial (directors only)
     * Uses FreeTrialService to ensure no webhook dependency per business rule.
     */
    public function availFreeTrial(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plan,plan_id',
        ]);

        $user = Auth::user();
        $plan = SubscriptionPlan::find($request->integer('plan_id'));
        
        if (!$plan) {
            return response()->json([
                'success' => false, 
                'message' => 'Subscription plan not found.'
            ], 404);
        }

        $freeTrialService = new FreeTrialService();
        $result = $freeTrialService->activateFreeTrial($user, $plan);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result['subscription'],
            'message' => $result['message'],
            'expires_at' => $result['expires_at'] ?? null
        ], 201);
    }

    /**
     * Check if the current user is eligible for a specific free trial plan
     */
    public function freeTrialEligibility(string $planId): JsonResponse
    {
        $user = Auth::user();
        $plan = SubscriptionPlan::find($planId);
        
        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found.'
            ], 404);
        }

        $freeTrialService = new FreeTrialService();
        $isEligible = $freeTrialService->isEligibleForFreeTrial($user, $plan);

        return response()->json([
            'success' => true,
            'eligible' => $isEligible,
            'plan_id' => $plan->plan_id,
            'plan_name' => $plan->plan_name,
            'is_free_trial' => $plan->is_free_trial,
            'trial_seconds' => $plan->trial_seconds,
            'user_role' => strtolower(optional($user->systemRole)->name)
        ]);
    }

    /**
     * Manual subscription activation for debugging payment issues
     * This should only be used when payment is confirmed but subscription didn't activate
     */
    public function manualActivateSubscription(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plan,plan_id',
            'payment_method' => 'nullable|string',
            'amount_paid' => 'required|numeric|min:0',
            'notes' => 'nullable|string'
        ]);

        $user = Auth::user();
        $plan = SubscriptionPlan::find($request->integer('plan_id'));
        
        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found.'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Expire existing active subscriptions
            FinancialAidSubscription::where('user_id', $user->id)
                ->where('status', 'Active')
                ->where('end_date', '>=', now()->toDateString())
                ->update(['status' => 'Expired']);

            // Create new active subscription
            $start = now()->toDateString();
            $end = Carbon::now()->addMonths((int)($plan->duration_in_months ?? 0))->addDays((int)($plan->duration_in_days ?? 0))->addSeconds((int)($plan->duration_in_seconds ?? 0))->toDateString();
            
            $subscription = FinancialAidSubscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->plan_id,
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'Active',
            ]);

            // Record transaction
            $transaction = SubscriptionTransaction::create([
                'user_id' => $user->id,
                'old_plan_id' => null,
                'new_plan_id' => $plan->plan_id,
                'payment_method' => $request->string('payment_method', 'MANUAL_ACTIVATION'),
                'amount_paid' => $request->get('amount_paid'),
                'transaction_date' => now(),
                'notes' => 'Manual activation: ' . ($request->string('notes') ?: 'Payment confirmed but auto-activation failed'),
                'status' => 'paid',
                'start_date' => $start,
                'end_date' => $end,
            ]);

            DB::commit();

            Log::info('Manual subscription activation', [
                'user_id' => $user->id,
                'plan_id' => $plan->plan_id,
                'subscription_id' => $subscription->subscription_id,
                'transaction_id' => $transaction->sub_transaction_id,
                'amount' => $request->get('amount_paid')
            ]);

            return response()->json([
                'success' => true,
                'data' => $subscription->load('subscriptionPlan'),
                'message' => 'Subscription manually activated successfully.',
                'transaction_id' => $transaction->sub_transaction_id
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Manual subscription activation failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->plan_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate subscription manually.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's subscription transaction history
     */
    public function transactionHistory(): JsonResponse
    {
        $user = Auth::user();
        
        // Get user's subscription transactions directly by user_id
        $transactions = SubscriptionTransaction::with(['user', 'oldPlan', 'newPlan'])
            ->where('user_id', $user->id)
            ->orderBy('transaction_date', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $transactions,
            'message' => 'Transaction history retrieved successfully.'
        ]);
    }

    /**
     * Admin: Get all subscription transactions
     */
    public function adminTransactions(): JsonResponse
    {
        $admin = Auth::user();
        if (!$admin || strtolower(optional($admin->systemRole)->name) !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        // Get all subscription transactions with relationships
        $transactions = SubscriptionTransaction::with(['user', 'oldPlan', 'newPlan'])
            ->orderBy('transaction_date', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $transactions,
            'message' => 'All subscription transactions retrieved successfully.'
        ]);
    }

    /** Admin: list pending subscriptions */
    public function adminPending(): JsonResponse
    {
        $admin = Auth::user();
        if (!$admin || strtolower(optional($admin->systemRole)->name) !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $subs = FinancialAidSubscription::with(['user', 'subscriptionPlan'])
            ->where('status', 'Pending')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $subs]);
    }

    /**
     * Generate a formal PDF receipt for a subscription (owner or admin).
     */
    public function receipt(string $id, Request $request)
    {
        $user = Auth::user();
        $subscription = FinancialAidSubscription::with(['subscriptionPlan','user'])->find($id);
        if (!$subscription) {
            return response()->json(['success' => false, 'message' => 'Subscription not found.'], 404);
        }
        $isOwner = $subscription->user_id === $user->id;
        $isAdmin = strtolower(optional($user->systemRole)->name) === 'admin';
        if (!$isOwner && !$isAdmin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        // Get latest transaction for this plan change (if any)
        $txn = SubscriptionTransaction::with('newPlan')
            ->where('user_id', $subscription->user_id)
            ->where('new_plan_id', $subscription->plan_id)
            ->orderBy('transaction_date', 'desc')
            ->first();

        $plan = $subscription->subscriptionPlan;
        $userModel = $subscription->user;

        // Format reference number like in email notification
        $transactionDate = $txn?->transaction_date ?? $subscription->created_at ?? now();
        $timestamp = $transactionDate->format('YmdHi');
        $transactionId = $txn?->sub_transaction_id ?? $subscription->subscription_id;
        $paddedId = str_pad($transactionId, 6, '0', STR_PAD_LEFT);
        $formattedRefNo = 'AIDP-' . $timestamp . '-' . $paddedId;

        $data = [
            'app_name' => config('app.name', 'AidPoint'),
            'generated_at' => now(),
            'receipt_no' => $txn?->sub_transaction_id ?? $subscription->subscription_id,
            'formatted_ref_no' => $formattedRefNo,
            'subscription' => $subscription,
            'plan' => $plan,
            'user' => $userModel,
            'transaction' => $txn,
            'transaction_date' => $transactionDate,
            'start_date' => $subscription->start_date ? (is_string($subscription->start_date) ? $subscription->start_date : $subscription->start_date->format('Y-m-d')) : 'N/A',
            'end_date' => $subscription->end_date ? (is_string($subscription->end_date) ? $subscription->end_date : $subscription->end_date->format('Y-m-d')) : 'N/A',
        ];

        $pdf = Pdf::loadView('pdf.subscription-receipt', $data);
        $pdf->setPaper('A4', 'portrait');
        $filename = 'subscription-receipt-' . $subscription->subscription_id . '.pdf';

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }
        return $pdf->stream($filename);
    }

    private function buildMinimalPdf(array $lines): string
    {
        // Build content stream with proper PDF text commands
        $content = "BT\n";
        $content .= "/F1 12 Tf\n"; // Set font
        $content .= "72 750 Td\n"; // Start position
        
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -18 Td\n"; // Move to next line
            }
            $content .= "(" . $this->escapePdfText($line) . ") Tj\n"; // Show text
        }
        $content .= "ET\n"; // End text
        
        $contentLength = strlen($content);
        
        // Build PDF structure
        $pdf = "%PDF-1.4\n";
        
        // Object 1: Catalog
        $obj1Offset = strlen($pdf);
        $pdf .= "1 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Catalog\n";
        $pdf .= "/Pages 2 0 R\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Object 2: Pages
        $obj2Offset = strlen($pdf);
        $pdf .= "2 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Pages\n";
        $pdf .= "/Kids [3 0 R]\n";
        $pdf .= "/Count 1\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Object 3: Page
        $obj3Offset = strlen($pdf);
        $pdf .= "3 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Page\n";
        $pdf .= "/Parent 2 0 R\n";
        $pdf .= "/MediaBox [0 0 612 792]\n";
        $pdf .= "/Contents 4 0 R\n";
        $pdf .= "/Resources << /Font << /F1 5 0 R >> >>\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Object 4: Content Stream
        $obj4Offset = strlen($pdf);
        $pdf .= "4 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Length $contentLength\n";
        $pdf .= ">>\n";
        $pdf .= "stream\n";
        $pdf .= $content;
        $pdf .= "endstream\n";
        $pdf .= "endobj\n";
        
        // Object 5: Font
        $obj5Offset = strlen($pdf);
        $pdf .= "5 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Font\n";
        $pdf .= "/Subtype /Type1\n";
        $pdf .= "/BaseFont /Helvetica\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Cross-reference table
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= "0 6\n";
        $pdf .= "0000000000 65535 f \n";
        $pdf .= sprintf("%010d 00000 n \n", $obj1Offset);
        $pdf .= sprintf("%010d 00000 n \n", $obj2Offset);
        $pdf .= sprintf("%010d 00000 n \n", $obj3Offset);
        $pdf .= sprintf("%010d 00000 n \n", $obj4Offset);
        $pdf .= sprintf("%010d 00000 n \n", $obj5Offset);
        
        // Trailer
        $pdf .= "trailer\n";
        $pdf .= "<<\n";
        $pdf .= "/Size 6\n";
        $pdf .= "/Root 1 0 R\n";
        $pdf .= ">>\n";
        $pdf .= "startxref\n";
        $pdf .= "$xrefOffset\n";
        $pdf .= "%%EOF";
        
        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        // Ensure text is not empty
        if (empty($text)) {
            return ' ';
        }
        
        // Replace PDF-special characters and control characters
        $text = str_replace(['\\', '(', ')', "\r", "\n", "\t"], ['\\\\', '\\(', '\\)', ' ', ' ', ' '], $text);
        
        // Remove any non-printable characters
        $text = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $text);
        
        // Ensure we have some content
        return empty(trim($text)) ? ' ' : $text;
    }

    /** Admin: activate a pending subscription */
    public function adminActivate(string $id): JsonResponse
    {
        $admin = Auth::user();
        if (!$admin || strtolower(optional($admin->systemRole)->name) !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $subscription = FinancialAidSubscription::with(['user', 'subscriptionPlan'])->find($id);
        if (!$subscription || $subscription->status !== 'Pending') {
            return response()->json(['success' => false, 'message' => 'Pending subscription not found.'], 404);
        }

        // Expire any currently active subscription for this user
        FinancialAidSubscription::where('user_id', $subscription->user_id)
            ->where('status', 'Active')
            ->where('end_date', '>=', Carbon::now()->toDateString())
            ->update(['status' => 'Expired']);

        // Activate selected subscription starting today
        $plan = $subscription->subscriptionPlan;
        $subscription->start_date = Carbon::now()->toDateString();
        $subscription->end_date = Carbon::now()->addMonths((int)($plan->duration_in_months ?? 0))->addDays((int)($plan->duration_in_days ?? 0))->toDateString();
        $subscription->status = 'Active';
        $subscription->save();

        return response()->json(['success' => true, 'data' => $subscription->fresh()->load('subscriptionPlan'), 'message' => 'Subscription activated.']);
    }
}
