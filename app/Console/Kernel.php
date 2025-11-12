<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Run renewal task daily at 2am
        $schedule->command('subscriptions:renew')->dailyAt('02:00');
        
        // Expire free trials every minute to ensure 30-second trials are handled promptly
        // This ensures webhook-independent operation as per business rule
        $schedule->command('subscriptions:expire-free-trials')->everyMinute();
        
        // Check for subscriptions expiring within 7 days and notify directors daily at 9am
        $schedule->command('subscription:check-expiration')->dailyAt('09:00');
        // Proactively notify directors about expiring subscriptions at 8am
        $schedule->command('directors:notify-expiring-subscriptions')->dailyAt('08:00');
        
        // Check expiring subscriptions and notify directors in real-time (runs daily at 8am)
        $schedule->command('subscriptions:check-expiring')->dailyAt('08:00');
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}