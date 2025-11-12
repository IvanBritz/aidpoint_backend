<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Notifications\EmailVerificationCodeNotification;

class EmailVerificationCodeController extends Controller
{
    public function resend(Request $request): JsonResponse
    {
        $user = Auth::user();
        $code = $user->issueEmailVerificationCode();
        $user->notify(new EmailVerificationCodeNotification($code));
        return response()->json(['message' => 'Verification code sent.']);
    }
}
