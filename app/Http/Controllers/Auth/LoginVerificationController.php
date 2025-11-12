<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginVerificationController extends Controller
{
    /**
     * Verify the login verification code
     */
    public function verify(Request $request): Response
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        if (!$user->requires_login_verification) {
            throw ValidationException::withMessages([
                'code' => ['No verification required for this account.'],
            ]);
        }

        if (!$user->hasValidLoginVerificationCode()) {
            throw ValidationException::withMessages([
                'code' => ['Verification code has expired. Please request a new one.'],
            ]);
        }

        if (!$user->verifyLoginCode($request->code)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        // Log the user in
        Auth::login($user);
        $request->session()->regenerate();

        return response()->noContent();
    }

    /**
     * Resend login verification code
     */
    public function resend(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        if (!$user->requires_login_verification && $user->is_first_login) {
            throw ValidationException::withMessages([
                'email' => ['No verification required for this account.'],
            ]);
        }

        // Generate and send new verification code
        $code = $user->generateLoginVerificationCode();
        $user->notify(new LoginVerificationCodeNotification($code));

        return response()->json([
            'message' => 'Verification code sent successfully.',
        ]);
    }
}