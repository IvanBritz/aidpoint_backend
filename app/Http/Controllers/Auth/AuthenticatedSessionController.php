<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): Response|JsonResponse
    {
        $request->authenticate();

        $user = Auth::user();

        // Require 5-digit code on first login (no last_login_at yet)
        if (!$user->last_login_at) {
            $code = $user->issueEmailVerificationCode();
            $user->notify(new EmailVerificationCodeNotification($code));
            Auth::logout();
            return response()->json([
                'message' => 'Verification required. A 5-digit code was sent to your email.',
                'requires_verification' => true,
                'email' => $user->email,
            ], 202);
        }

        $request->session()->regenerate();

        return response()->noContent();
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
