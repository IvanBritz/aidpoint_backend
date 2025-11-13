<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use App\Notifications\EmailVerificationCodeNotification;

class UserPasswordController extends Controller
{
    /**
     * Update the authenticated user's password.
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        // If NOT first-time password change, require a 5-digit verification code
        if (!$user->must_change_password) {
            $code = (string) $request->input('verification_code', '');
            if (!$code || !$user->verifyEmailVerificationCode($code)) {
                // Issue code (reuse if still valid)
                $newCode = $user->issueEmailVerificationCode();
                $user->notify(new EmailVerificationCodeNotification($newCode));
                return response()->json([
                    'message' => 'Verification required. A 5-digit code was sent to your email.',
                    'requires_verification' => true,
                ], 202);
            }
        }

        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->string('password')),
            'must_change_password' => false,
            'last_login_at' => now(), // Mark as logged in to prevent verification loop
        ])->save();

        // Regenerate session to keep user logged in after password change
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }
}