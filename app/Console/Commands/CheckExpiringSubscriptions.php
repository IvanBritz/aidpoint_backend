<?php

namespace App\Console\Commands;

use App\Services\DirectorNotificationService;
use Illuminate\Console\Command;

class CheckExpiringSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check-expiring';

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
        } catch (\Exception $e) {
            $this->error('Error checking expiring subscriptions: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
