<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DirectorNotificationService;

class CheckSubscriptionExpiration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:check-expiration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for subscriptions expiring within 7 days and notify directors';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expiring subscriptions...');
        
        try {
            DirectorNotificationService::checkAndNotifyExpiringSubscriptions();
            $this->info('Successfully checked and notified directors about expiring subscriptions.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error checking subscriptions: ' . $e->getMessage());
            return 1;
        }
    }
}
