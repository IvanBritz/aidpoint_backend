<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/dashboard');
        }

        // Generate (or reuse existing valid) verification code instead of link
        $code = $request->user()->generateEmailVerificationCode();
        $request->user()->notify(new EmailVerificationCodeNotification($code));

        return response()->json(['status' => 'verification-code-sent']);
    }
}
