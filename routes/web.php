<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Redirect users back to the frontend after PayMongo redirects
Route::get('/paymongo/success', function () {
    $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
    // Send users to the dashboard with a success indicator
    return redirect()->away($frontend . '/dashboard?paid=1');
});

Route::get('/paymongo/cancel', function () {
    $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
    return redirect()->away($frontend . '/plans?cancelled=1');
});

require __DIR__.'/auth.php';
