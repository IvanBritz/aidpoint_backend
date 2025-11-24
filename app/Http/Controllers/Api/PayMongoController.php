<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAidSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Notifications\SubscriptionPaymentReceiptNotification;
use App\Services\AccessSuspensionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PayMongoController extends Controller
{
    /**
     * Create a Payment Intent for the selected plan.
     * Frontend should use PayMongo JS to create a payment method (with the public key)
     * and then call /payments/paymongo/confirm with the payment_method_id.
     */
    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plan,plan_id',
        ]);

        $user = Auth::user();
        $plan = SubscriptionPlan::findOrFail($request->integer('plan_id'));

        $amount = (int) round(((float) $plan->price) * 100);
        $secret = config('services.paymongo.secret');
        if (!$secret) {
            return response()->json(['success' => false, 'message' => 'PayMongo secret key not configured.'], 500);
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'amount' => $amount,
                    'currency' => 'PHP',
                    'payment_method_allowed' => ['card'],
                    'capture_type' => 'automatic',
                    'statement_descriptor' => 'AidPoint Subscription',
                    'description' => 'Subscription: ' . $plan->plan_name,
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'plan_id' => (string) $plan->plan_id,
                        'plan_price' => (string) $plan->price,
                        'plan_duration_in_months' => (string) $plan->duration_in_months,
                        'app_context' => 'aidpoint_subscription',
                    ],
                ],
            ],
        ];

        $auth = base64_encode($secret . ':');
        $resp = Http::withHeaders([
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ])->post('https://api.paymongo.com/v1/payment_intents', $payload);

        if (!$resp->successful()) {
            Log::error('PayMongo intent create error', ['body' => $resp->body()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent.',
                'error' => $resp->json(),
            ], 500);
        }

        $data = $resp->json('data');
        $intentId = $data['id'] ?? null;
        $clientKey = $data['attributes']['client_key'] ?? null;

        // Create a placeholder pending transaction row for traceability
        SubscriptionTransaction::create([
            'user_id' => $user->id,
            'old_plan_id' => null,
            'new_plan_id' => $plan->plan_id,
            'payment_method' => 'CARD',
            'amount_paid' => $plan->price,
            'transaction_date' => now(),
            'notes' => 'Payment intent created',
            'payment_intent_id' => $intentId,
            'status' => 'pending',
            'start_date' => now()->toDateString(),
            'end_date' => Carbon::now()->addMonths($plan->duration_in_months)->toDateString(),
        ]);

        // In-app notification: payment pending (idempotent)
        try {
            \App\Models\Notification::notifySubscriptionStatus(
                $user->id,
                (string) $intentId,
                'pending',
                [
                    'user_name' => ($user->full_name ?? trim(($user->firstname ?? '').' '.($user->lastname ?? ''))) ?: 'User',
                    'plan_name' => $plan->plan_name,
                    'amount' => (float) $plan->price,
                    'action_link' => url('/app/subscriptions'),
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to send pending notification', ['e' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'payment_intent_id' => $intentId,
            'client_key' => $clientKey,
            'public_key' => config('services.paymongo.public'), // convenience for frontend
        ]);
    }

    /**
     * Attach and confirm a payment intent using a client-created payment method ID.
     * After attach, we verify the intent and, if paid, record the transaction and activate the subscription.
     */
    public function confirmPaymentIntent(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
            'payment_method_id' => 'required|string',
        ]);

        $secret = config('services.paymongo.secret');
        $auth = base64_encode($secret . ':');
        $id = $request->string('payment_intent_id')->toString();

        $payload = [
            'data' => [
                'attributes' => [
                    'payment_method' => $request->string('payment_method_id')->toString(),
                    'client_key' => $request->string('client_key', '')->toString(),
                    'return_url' => url('/paymongo/success'),
                ],
            ],
        ];

        $resp = Http::withHeaders([
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ])->post("https://api.paymongo.com/v1/payment_intents/{$id}/attach", $payload);

        if (!$resp->successful()) {
            Log::error('PayMongo intent attach error', ['body' => $resp->body()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment intent.',
                'error' => $resp->json(),
            ], 422);
        }

        // Persist the payment method for auto-renewals
        $user = Auth::user();
        if ($user && empty($user->paymongo_payment_method_id)) {
            $user->paymongo_payment_method_id = $request->string('payment_method_id')->toString();
            $user->save();
        }

        // Immediately attempt to verify and finalize if already paid
        $finalized = $this->verifyAndFinalizeIntent($id, $auth);

        return response()->json([
            'success' => true,
            'data' => $resp->json('data'),
            'finalized' => $finalized,
        ]);
    }

    /**
     * Create a PayMongo Checkout Session for the given plan and e-wallet method.
     * Supported methods: gcash, paymaya
     */
    public function createCheckout(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plan,plan_id',
            'method' => 'nullable|in:gcash,paymaya',
            'return_url' => 'nullable|url',
        ]);

        $user = Auth::user();
        $plan = SubscriptionPlan::findOrFail($request->integer('plan_id'));

        // Amount in centavos (PayMongo uses the smallest currency unit)
        $amount = (int) round(((float) $plan->price) * 100);

        $secret = config('services.paymongo.secret');
        if (!$secret) {
            return response()->json(['success' => false, 'message' => 'PayMongo secret key not configured.'], 500);
        }

        // If caller passes a return_url, redirect back there with status flags
        $baseReturn = $request->string('return_url')->toString();
        if ($baseReturn) {
            $join = str_contains($baseReturn, '?') ? '&' : '?';
            $success = $baseReturn . $join . 'paid=1';
            $cancel  = $baseReturn . $join . 'cancelled=1';
        } else {
            $success = url('/paymongo/success');
            $cancel  = url('/paymongo/cancel');
        }

        // Build checkout session payload
        $payload = [
            'data' => [
                'attributes' => [
                    'cancel_url' => $cancel,
                    'success_url' => $success,
                    'description' => 'Subscription: ' . $plan->plan_name,
                    'line_items' => [[
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'name' => $plan->plan_name,
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => ($request->filled('method') ? [$request->string('method')->toString()] : ['gcash','paymaya']),
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'plan_id' => (string) $plan->plan_id,
                        'plan_price' => (string) $plan->price,
                        'plan_duration_in_months' => (string) $plan->duration_in_months,
                        'app_context' => 'aidpoint_subscription',
                    ],
                ],
            ],
        ];

        $auth = base64_encode($secret . ':');
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ])->post('https://api.paymongo.com/v1/checkout_sessions', $payload);

        if (!$response->successful()) {
            Log::error('PayMongo checkout error', ['body' => $response->body()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session.',
                'error' => $response->json(),
            ], 500);
        }

        $data = $response->json('data');
        $checkoutUrl = $data['attributes']['checkout_url'] ?? null;
        $checkoutId  = $data['id'] ?? null;

        // Do NOT create any pending subscription or transaction here.
        // We will verify client-side via a follow-up API call and then record the transaction.

        return response()->json([
            'success' => true,
            'checkout_url' => $checkoutUrl,
            'checkout_id' => $checkoutId,
        ]);
    }

    /**
     * Verify a Payment Intent and, if paid, record the transaction and activate the subscription.
     */
    public function verifyIntent(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
        ]);
        $secret = config('services.paymongo.secret');
        $auth = base64_encode($secret . ':');
        $ok = $this->verifyAndFinalizeIntent($request->string('payment_intent_id')->toString(), $auth);
        return response()->json(['success' => true, 'finalized' => $ok]);
    }

    /**
     * Verify a Checkout Session and, if paid, record the transaction and activate the subscription.
     */
    public function verifyCheckout(Request $request)
    {
        $request->validate([
            'checkout_id' => 'required|string',
        ]);
        $secret = config('services.paymongo.secret');
        $auth = base64_encode($secret . ':');

        $id = $request->string('checkout_id')->toString();
        $resp = Http::withHeaders([
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ])->get("https://api.paymongo.com/v1/checkout_sessions/{$id}", [
            'include' => 'payments',
        ]);

        if (!$resp->successful()) {
            Log::warning('PayMongo verify checkout failed', ['id' => $id, 'body' => $resp->body()]);
            return response()->json(['success' => false, 'message' => 'Failed to verify checkout session.'], 422);
        }

        $session = $resp->json('data');
        $sessionStatus = data_get($session, 'attributes.status', 'pending');
        
        // Log session details for debugging
        Log::info('PayMongo checkout verification', [
            'checkout_id' => $id,
            'session_status' => $sessionStatus,
            'has_payments' => !empty(data_get($session, 'attributes.payments.data'))
        ]);
        
        $payment = data_get($session, 'attributes.payments.data.0') ?? data_get($session, 'attributes.payments.0');
        if (!$payment) {
            return response()->json([
                'success' => true, 
                'finalized' => false,
                'session_status' => $sessionStatus,
                'debug' => 'No payment found in session'
            ]);
        }

        $status = data_get($payment, 'attributes.status');
        if ($status !== 'paid') {
            return response()->json([
                'success' => true, 
                'finalized' => false,
                'payment_status' => $status,
                'debug' => 'Payment not in paid status'
            ]);
        }

        // Get metadata from session attributes first, then payment metadata
        $sessionMetadata = (array) data_get($session, 'attributes.metadata', []);
        $paymentMetadata = (array) data_get($payment, 'attributes.metadata', []);
        $metadata = array_merge($sessionMetadata, $paymentMetadata);
        
        $userId = (int) ($metadata['user_id'] ?? 0);
        $planId = (int) ($metadata['plan_id'] ?? 0);
        $txnId = (string) data_get($payment, 'id');
        $intentId = (string) data_get($payment, 'attributes.payment_intent_id');

        if (!$userId || !$planId) {
            return response()->json(['success' => false, 'message' => 'Missing payment metadata.'], 422);
        }

        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Plan not found.'], 404);
        }

        $pm = strtoupper((string) data_get($payment, 'attributes.source.type', $metadata['method'] ?? 'PAYMONGO'));
        $ok = $this->finalizePaidTransaction($userId, $plan, $pm, $intentId, $txnId);

        return response()->json(['success' => true, 'finalized' => $ok]);
    }

    /**
     * Debug endpoint to inspect a checkout session
     */
    public function debugCheckout(string $checkoutId)
    {
        $secret = config('services.paymongo.secret');
        if (!$secret) {
            return response()->json(['error' => 'PayMongo secret not configured'], 500);
        }

        $auth = base64_encode($secret . ':');
        $resp = Http::withHeaders([
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ])->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutId}", [
            'include' => 'payments',
        ]);

        if (!$resp->successful()) {
            return response()->json([
                'error' => 'Failed to retrieve checkout session',
                'status' => $resp->status(),
                'response' => $resp->body()
            ], $resp->status());
        }

        $session = $resp->json('data');
        $payments = data_get($session, 'attributes.payments.data', []);
        
        return response()->json([
            'checkout_id' => $checkoutId,
            'session_status' => data_get($session, 'attributes.status'),
            'session_metadata' => data_get($session, 'attributes.metadata', []),
            'payments_count' => count($payments),
            'payments' => $payments,
            'raw_session' => $session
        ]);
    }

    /**
     * Webhook endpoint to receive PayMongo events.
     * Per policy, this endpoint will not update subscription state; it only acknowledges and logs.
     * IMPORTANT: Free trials (30 seconds) are never processed via webhooks - they are handled directly server-side.
     */
    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true) ?: [];

        // Verify webhook signature when configured
        $secret = config('services.paymongo.webhook_secret');
        $sigHeader = $request->header('Paymongo-Signature') ?? $request->header('PayMongo-Signature');
        if ($secret && $sigHeader) {
            // Expected format: t=timestamp,v1=signature
            $parts = collect(explode(',', (string) $sigHeader))
                ->mapWithKeys(function ($kv) {
                    [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, null);
                    return [$k => $v];
                });
            $ts = $parts->get('t');
            $v1 = $parts->get('v1');
            if ($ts && $v1) {
                $signed = $ts . '.' . $raw;
                $calc = hash_hmac('sha256', $signed, $secret);
                if (!hash_equals($v1, $calc)) {
                    Log::warning('PayMongo webhook signature mismatch');
                    return response()->json(['ok' => false], 400);
                }
            }
        }

        // Check if this is related to a free trial transaction
        $eventData = data_get($payload, 'data.attributes');
        $metadata = (array) data_get($eventData, 'metadata', []);
        
        if ($this->isFreeTrialRelated($metadata, $payload)) {
            Log::info('PayMongo webhook ignored - free trial transaction (handled server-side)', [
                'event_type' => data_get($payload, 'data.type'),
                'metadata' => $metadata
            ]);
            return response()->json(['ok' => true, 'message' => 'Free trial - handled server-side']);
        }

        Log::info('PayMongo webhook received (no-op)', ['payload' => $payload]);
        return response()->json(['ok' => true]);
    }

    /**
     * Internal: Verify a payment intent and finalize if paid.
     */
    private function verifyAndFinalizeIntent(string $intentId, string $auth): bool
    {
        $resp = Http::withHeaders([
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ])->get("https://api.paymongo.com/v1/payment_intents/{$intentId}", [
            'include' => 'payments',
        ]);

        if (!$resp->successful()) {
            Log::warning('PayMongo verify intent failed', ['id' => $intentId, 'body' => $resp->body()]);
            return false;
        }

        $intent = $resp->json('data');
        $payment = data_get($intent, 'attributes.payments.0');
        if (!$payment) {
            return false;
        }

        $status = data_get($payment, 'attributes.status');
        if ($status !== 'paid') {
            return false;
        }

        $metadata = (array) data_get($payment, 'attributes.metadata', []);
        $userId = (int) ($metadata['user_id'] ?? 0);
        $planId = (int) ($metadata['plan_id'] ?? 0);
        $txnId = (string) data_get($payment, 'id');

        if (!$userId || !$planId) {
            Log::warning('Missing metadata on paid payment', ['intent_id' => $intentId]);
            return false;
        }

        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            return false;
        }

        $pm = strtoupper((string) data_get($payment, 'attributes.source.type', $metadata['method'] ?? 'PAYMONGO'));
        return $this->finalizePaidTransaction($userId, $plan, $pm, $intentId, $txnId);
    }

    /**
     * Internal: record paid transaction and activate subscription from it. Idempotent.
     */
    private function finalizePaidTransaction(int $userId, SubscriptionPlan $plan, string $paymentMethod, ?string $intentId, ?string $txnId): bool
    {
        try {
            DB::beginTransaction();
            
            // Idempotency: if we already recorded a PAID transaction for this intent, do nothing
            if ($intentId) {
                $exists = SubscriptionTransaction::where('payment_intent_id', $intentId)
                    ->where('status', 'paid')
                    ->exists();
                if ($exists) {
                    DB::rollBack();
                    Log::info('Transaction already finalized', ['intent_id' => $intentId]);
                    return true;
                }
            }

            // Extend existing active subscription and switch to new plan
            $current = FinancialAidSubscription::where('user_id', $userId)
                ->where('status', 'Active')
                ->where('end_date', '>=', now()->toDateString())
                ->orderByDesc('end_date')
                ->first();

            $baseEnd = $current && $current->end_date ? Carbon::parse($current->end_date) : Carbon::now();
            $newEnd = (clone $baseEnd)
                ->addMonths((int)($plan->duration_in_months ?? 0))
                ->addDays((int)($plan->duration_in_days ?? 0))
                ->addSeconds((int)($plan->duration_in_seconds ?? 0))
                ->toDateString();

            if ($current) {
                $oldPlanId = $current->plan_id;
                $current->update([
                    'plan_id' => $plan->plan_id,
                    // keep original start_date; only extend the end_date
                    'end_date' => $newEnd,
                    'status' => 'Active',
                ]);
                $sub = $current;
                Log::info('Extended active subscription and switched plan', [
                    'subscription_id' => $sub->subscription_id,
                    'user_id' => $userId,
                    'old_plan_id' => $oldPlanId,
                    'new_plan_id' => $plan->plan_id,
                    'new_end_date' => $newEnd,
                ]);
            } else {
                // No active subscription; create new starting now
                $start = now()->toDateString();
                $end = Carbon::now()
                    ->addMonths((int)($plan->duration_in_months ?? 0))
                    ->addDays((int)($plan->duration_in_days ?? 0))
                    ->addSeconds((int)($plan->duration_in_seconds ?? 0))
                    ->toDateString();
                $sub = FinancialAidSubscription::create([
                    'user_id' => $userId,
                    'plan_id' => $plan->plan_id,
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => 'Active',
                ]);
                Log::info('Created new active subscription', [
                    'subscription_id' => $sub->subscription_id,
                    'user_id' => $userId,
                    'plan_id' => $plan->plan_id,
                    'new_end_date' => $end,
                ]);
            }

            // Record the transaction as paid (single source of truth)
            $transaction = SubscriptionTransaction::create([
                'user_id' => $userId,
                'old_plan_id' => isset($oldPlanId) ? $oldPlanId : null,
                'new_plan_id' => $plan->plan_id,
                'payment_method' => $paymentMethod,
                'amount_paid' => $plan->price,
                'transaction_date' => now(),
                'notes' => 'PayMongo payment ' . (string) $txnId,
                'payment_intent_id' => $intentId,
                'status' => 'paid',
                'start_date' => $sub->start_date,
                'end_date' => $sub->end_date,
            ]);

            DB::commit();
            
            Log::info('Payment finalized successfully', [
                'user_id' => $userId,
                'plan_id' => $plan->plan_id,
                'transaction_id' => $transaction->sub_transaction_id,
                'subscription_id' => $sub->subscription_id,
                'amount' => $plan->price
            ]);

            // Restore access for director and all associated users (employees, beneficiaries)
            try {
                if ($director = User::find($userId)) {
                    (new AccessSuspensionService())->restoreCenterAccessForDirector($director);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to restore access after successful payment', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }

            // Notify approval and completion (idempotent per implementation)
            try {
                \App\Models\Notification::notifySubscriptionStatus(
                    $userId,
                    (string) ($intentId ?: $txnId),
                    'approved',
                    [
                        'plan_name' => $plan->plan_name,
                        'amount' => (float) $plan->price,
                        'action_link' => url('/app/subscriptions'),
                    ]
                );
                \App\Models\Notification::notifySubscriptionStatus(
                    $userId,
                    (string) ($intentId ?: $txnId),
                    'completed',
                    [
                        'plan_name' => $plan->plan_name,
                        'amount' => (float) $plan->price,
                        'action_link' => url('/app/subscriptions'),
                    ]
                );
            } catch (\Throwable $e) {
                \Log::warning('Failed to send payment success notifications', ['e' => $e->getMessage()]);
            }

            // Send email receipt with transaction/reference number
            try {
                if ($director = User::find($userId)) {
                    $userName = trim(($director->firstname ?? '') . ' ' . ($director->lastname ?? ''));
                    if (empty($userName)) {
                        $userName = $director->email ?? 'Valued Customer';
                    }

                    $director->notify(new SubscriptionPaymentReceiptNotification([
                        'receipt_no' => $transaction->sub_transaction_id,
                        'plan_name' => $plan->plan_name,
                        'amount' => (float) $plan->price,
                        'payment_method' => $paymentMethod,
                        'transaction_date' => $transaction->transaction_date,
                        'start_date' => $start,
                        'end_date' => $end,
                        'user_name' => $userName,
                    ]));

                    Log::info('Email receipt sent successfully', [
                        'user_id' => $userId,
                        'receipt_no' => $transaction->sub_transaction_id,
                        'email' => $director->email,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send email receipt', [
                    'user_id' => $userId,
                    'transaction_id' => $transaction->sub_transaction_id,
                    'error' => $e->getMessage()
                ]);
            }

            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to finalize paid transaction', [
                'user_id' => $userId,
                'plan_id' => $plan->plan_id,
                'intent_id' => $intentId,
                'txn_id' => $txnId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Check if a PayMongo webhook payload is related to a free trial transaction.
     * Free trials should never be processed via webhooks as per business rule.
     */
    private function isFreeTrialRelated(array $metadata, array $payload): bool
    {
        // Check metadata for free trial indicators
        if (isset($metadata['plan_id'])) {
            $planId = (int) $metadata['plan_id'];
            $plan = \App\Models\SubscriptionPlan::find($planId);
            
            if ($plan && $plan->is_free_trial) {
                return true;
            }
        }

        // Check if plan name contains free trial indicators
        $description = data_get($payload, 'data.attributes.description', '');
        if (stripos($description, 'free trial') !== false || stripos($description, '30 second') !== false) {
            return true;
        }

        // Check if amount is 0 or very small (typical for free trials)
        $amount = data_get($payload, 'data.attributes.amount', 0);
        if ($amount <= 100) { // 100 centavos = 1 peso, anything less likely a free trial
            // Double-check with plan lookup if we have plan_id
            if (isset($metadata['plan_id'])) {
                $planId = (int) $metadata['plan_id'];
                $plan = \App\Models\SubscriptionPlan::find($planId);
                return $plan && $plan->is_free_trial;
            }
        }

        return false;
    }
}
