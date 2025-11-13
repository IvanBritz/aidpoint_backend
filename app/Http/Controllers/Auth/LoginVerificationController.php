<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;

class LoginVerificationController extends Controller
{
    /**
     * Verify the 5-digit code and log user in.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'code' => ['required','digits:5'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            throw ValidationException::withMessages(['email' => ['User not found.']]);
        }

        if (!$user->verifyEmailVerificationCode($data['code'])) {
            throw ValidationException::withMessages(['code' => ['Invalid or expired code.']]);
        }

        // Mark email as verified if not already
        if (!$user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Successful verification -> log them in
        Auth::login($user);
        $request->session()->regenerate();

        // First login completed
        if (!$user->last_login_at) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Resend a 5-digit code.
     */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required','email'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            throw ValidationException::withMessages(['email' => ['User not found.']]);
        }

        $code = $user->issueEmailVerificationCode();
        $user->notify(new EmailVerificationCodeNotification($code));

        return response()->json(['message' => 'Verification code sent.']);
    }
}
