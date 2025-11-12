<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Notifications\LoginVerificationCodeNotification;
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
        
        // Bypass login verification for admins (systemrole_id = 1)
        if ((int) $user->systemrole_id !== 1 && $user->is_first_login) {
            // Generate and send verification code
            $code = $user->generateLoginVerificationCode();
            $user->notify(new LoginVerificationCodeNotification($code));
            
            // Log the user out temporarily
            Auth::logout();
            
            // Return a specific response indicating verification is required
            return response()->json([
                'message' => 'Login verification required. Please check your email for verification code.',
                'requires_verification' => true,
                'email' => $user->email
            ], 202); // 202 Accepted but needs further action
        }
        
        // Proceed normally (first login or admin bypass)
        $user->update([
            'is_first_login' => false,
            'last_login_at' => now(),
            'login_attempt_count' => $user->login_attempt_count + 1,
            'requires_login_verification' => false,
            'login_verification_code' => null,
            'login_verification_code_expires_at' => null,
        ]);

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
