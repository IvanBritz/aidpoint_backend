<?php

namespace App\Console\Commands;

use App\Services\DirectorNotificationService;
use Illuminate\Console\Command;

class NotifyDirectorsOfExpiringSubscriptions extends Command
{
    protected $signature = 'directors:notify-expiring-subscriptions';
    protected $description = 'Notify directors about subscriptions expiring within a week';

    public function handle()
    {
        $this->info('Checking for expiring subscriptions...');
        
        try {
            DirectorNotificationService::checkAndNotifyExpiringSubscriptions();
            $this->info('Successfully notified directors about expiring subscriptions.');
        } catch (\Exception $e) {
            $this->error('Failed to notify directors: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}