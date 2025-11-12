<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\LoginVerificationController;
use App\Http\Controllers\Auth\EmailVerificationCodeController;
use Illuminate\Support\Facades\Route;

// Redirect GET requests to /register to the frontend
Route::get('/register', function () {
    return redirect()->away(config('app.frontend_url') . '/register');
})->middleware('guest');

Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('guest')
    ->name('register');

// Redirect GET requests to /login to the frontend
Route::get('/login', function () {
    return redirect()->away(config('app.frontend_url') . '/login');
})->middleware('guest');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('guest')
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('guest')
    ->name('password.store');

// Login verification (5-digit code)
Route::post('/login-verification', [LoginVerificationController::class, 'verify'])->middleware(['guest','throttle:6,1']);
Route::post('/login-verification/resend', [LoginVerificationController::class, 'resend'])->middleware(['guest','throttle:3,1']);

// Authenticated: resend code (for password change or other)
Route::post('/email/resend-code', [EmailVerificationCodeController::class, 'resend'])->middleware(['auth','throttle:3,1']);

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Google OAuth routes
Route::get('/auth/google/redirect', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'redirect'])
    ->middleware('guest');
Route::get('/auth/google/callback', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'callback'])
    ->middleware('guest');
