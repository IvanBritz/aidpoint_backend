<?php

namespace App\Console\Commands;

use App\Models\FinancialAidSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew {--dry-run}';
    protected $description = 'Charge due subscriptions and extend their periods via PayMongo';

    public function handle(): int
    {
        $today = Carbon::today();
        $secret = config('services.paymongo.secret');
        if (!$secret) {
            $this->error('PAYMONGO_SECRET_KEY not configured.');
            return self::FAILURE;
        }
        $auth = base64_encode($secret . ':');
        $dry = (bool) $this->option('dry-run');

        $due = FinancialAidSubscription::where('status', 'Active')
            ->whereDate('end_date', '<=', $today->toDateString())
            ->get();

        $this->info('Found ' . $due->count() . ' subscriptions due for renewal.');

        foreach ($due as $sub) {
            $user = User::find($sub->user_id);
            if (!$user?->paymongo_payment_method_id) {
                $this->warn("User {$sub->user_id} has no saved payment method. Skipping.");
                continue;
            }
            $plan = SubscriptionPlan::find($sub->plan_id);
            if (!$plan) continue;

            $amount = (int) round(((float) $plan->price) * 100);

            if ($dry) {
                $this->line("[DRY-RUN] Would charge user {$user->id} PHP {$plan->price} for plan {$plan->plan_name}");
                continue;
            }

            // 1) Create payment intent
            $piPayload = [
                'data' => [
                    'attributes' => [
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'payment_method_allowed' => ['card'],
                        'capture_type' => 'automatic',
                        'description' => 'Subscription renewal: ' . $plan->plan_name,
                        'metadata' => [
                            'user_id' => (string) $user->id,
                            'plan_id' => (string) $plan->plan_id,
                            'renewal' => '1',
                        ],
                    ],
                ],
            ];

            $create = Http::withHeaders([
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json',
            ])->post('https://api.paymongo.com/v1/payment_intents', $piPayload);

            if (!$create->successful()) {
                Log::error('Renewal PI create failed', ['user' => $user->id, 'body' => $create->body()]);
                $this->error('Failed to create PI for user ' . $user->id);
                continue;
            }

            $intentId = data_get($create->json(), 'data.id');

            // 2) Attach saved payment method (off-session)
            $attachPayload = [
                'data' => [
                    'attributes' => [
                        'payment_method' => $user->paymongo_payment_method_id,
                    ],
                ],
            ];
            $attach = Http::withHeaders([
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json',
            ])->post("https://api.paymongo.com/v1/payment_intents/{$intentId}/attach", $attachPayload);

            if (!$attach->successful()) {
                Log::error('Renewal PI attach failed', ['user' => $user->id, 'body' => $attach->body()]);
                SubscriptionTransaction::create([
                    'user_id' => $user->id,
                    'old_plan_id' => $sub->plan_id,
                    'new_plan_id' => $sub->plan_id,
                    'payment_method' => 'CARD',
                    'amount_paid' => $plan->price,
                    'transaction_date' => now(),
                    'notes' => 'Auto-renew attach failed',
                    'payment_intent_id' => $intentId,
                    'status' => 'failed',
                ]);
                // Notify failure with retry link
                try {
                    \App\Models\Notification::notifySubscriptionStatus(
                        $user->id,
                        (string) $intentId,
                        'failed',
                        [
                            'plan_name' => $plan->plan_name,
                            'amount' => (float) $plan->price,
                            'action_link' => url('/app/subscriptions?retry=1'),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to send renewal failed notification', ['e' => $e->getMessage()]);
                }
                continue;
            }

            // 3) On success, extend subscription and record transaction
            $start = Carbon::today()->toDateString();
            $end = Carbon::today()->addMonths((int)($plan->duration_in_months ?? 0))->addDays((int)($plan->duration_in_days ?? 0))->toDateString();

            $sub->update([
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'Active',
            ]);

            SubscriptionTransaction::create([
                'user_id' => $user->id,
                'old_plan_id' => $sub->plan_id,
                'new_plan_id' => $sub->plan_id,
                'payment_method' => 'CARD',
                'amount_paid' => $plan->price,
                'transaction_date' => now(),
                'notes' => 'Auto-renew via PayMongo',
                'payment_intent_id' => $intentId,
                'status' => 'paid',
                'start_date' => $start,
                'end_date' => $end,
            ]);

            // Notify user of renewal completion
            try {
                \App\Models\Notification::notifySubscriptionStatus(
                    $user->id,
                    (string) $intentId,
                    'completed',
                    [
                        'plan_name' => $plan->plan_name,
                        'amount' => (float) $plan->price,
                        'action_link' => url('/app/subscriptions'),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send renewal completion notification', ['e' => $e->getMessage()]);
            }

            // Restore access for director + center after successful renewal
            try {
                (new \App\Services\AccessSuspensionService())->restoreCenterAccessForDirector($user);
            } catch (\Throwable $e) {
                Log::warning('Failed to restore access after renewal', ['e' => $e->getMessage()]);
            }

            $this->info("Renewed subscription for user {$user->id} until {$end}");
        }

        return self::SUCCESS;
    }
}