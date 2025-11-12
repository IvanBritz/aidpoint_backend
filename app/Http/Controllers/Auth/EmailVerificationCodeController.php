<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EmailVerificationCodeController extends Controller
{
    /**
     * Verify the email verification code
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (!$user) {
            throw ValidationException::withMessages([
                'code' => ['User not found.'],
            ]);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        if (!$user->hasValidEmailVerificationCode()) {
            throw ValidationException::withMessages([
                'code' => ['Verification code has expired. Please request a new one.'],
            ]);
        }

        if (!$user->verifyEmailCode($request->code)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /**
     * Resend email verification code
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        // Generate (or reuse existing valid) verification code
        $code = $user->generateEmailVerificationCode();
        $user->notify(new EmailVerificationCodeNotification($code));

        return response()->json([
            'status' => 'verification-code-sent',
            'message' => 'Verification code sent successfully.',
        ]);
    }
}