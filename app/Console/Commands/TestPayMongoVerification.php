<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestPayMongoVerification extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'paymongo:test-verification {checkout_id? : The checkout ID to test verification}';

    /**
     * The console command description.
     */
    protected $description = 'Test PayMongo checkout verification process';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checkoutId = $this->argument('checkout_id');
        
        if (!$checkoutId) {
            $this->error('Please provide a checkout ID to test');
            return Command::FAILURE;
        }

        $this->info("Testing PayMongo verification for checkout ID: {$checkoutId}");
        
        $secret = config('services.paymongo.secret');
        if (!$secret) {
            $this->error('PayMongo secret key not configured');
            return Command::FAILURE;
        }

        $auth = base64_encode($secret . ':');
        $resp = Http::withHeaders([
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ])->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutId}", [
            'include' => 'payments',
        ]);

        if (!$resp->successful()) {
            $this->error('Failed to retrieve checkout session');
            $this->line('Response: ' . $resp->body());
            return Command::FAILURE;
        }

        $session = $resp->json('data');
        $this->info('Checkout Session Status: ' . data_get($session, 'attributes.status', 'unknown'));
        
        $payments = data_get($session, 'attributes.payments.data', []);
        $this->info('Number of payments: ' . count($payments));
        
        if (empty($payments)) {
            $this->warn('No payments found for this checkout session');
            return Command::SUCCESS;
        }

        $payment = $payments[0];
        $paymentStatus = data_get($payment, 'attributes.status');
        $this->info('Payment Status: ' . $paymentStatus);
        
        if ($paymentStatus === 'paid') {
            $this->info('✅ Payment is marked as paid');
            
            // Check metadata
            $sessionMetadata = (array) data_get($session, 'attributes.metadata', []);
            $paymentMetadata = (array) data_get($payment, 'attributes.metadata', []);
            $metadata = array_merge($sessionMetadata, $paymentMetadata);
            
            $this->table(
                ['Key', 'Value'],
                collect($metadata)->map(fn($value, $key) => [$key, $value])->toArray()
            );
            
            $userId = (int) ($metadata['user_id'] ?? 0);
            $planId = (int) ($metadata['plan_id'] ?? 0);
            
            if ($userId && $planId) {
                $user = User::find($userId);
                $plan = SubscriptionPlan::find($planId);
                
                $this->info("User: {$user->full_name} (ID: {$userId})");
                $this->info("Plan: {$plan->plan_name} (ID: {$planId}, Price: ₱{$plan->price})");
                
                $this->info('✅ All required metadata is present');
            } else {
                $this->warn('❌ Missing required metadata (user_id or plan_id)');
            }
        } else {
            $this->warn('❌ Payment is not in paid status');
        }
        
        return Command::SUCCESS;
    }
}