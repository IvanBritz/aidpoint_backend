<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * Handle an incoming request.
     * If the authenticated user is archived or their center has no active subscription, block with 403.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Allow admins regardless (admin portal maintenance etc.)
        $role = strtolower(optional($user->systemRole)->name);
        if ($role === 'admin') {
            return $next($request);
        }

        // Allow-list of endpoints required to renew while suspended or to render lockout screens
        $allowedPatterns = [
            // Public endpoints used before/without auth
            'api/public/*',
            // Plans and lookups for renewal UI
            'api/subscription-plans*',
            'api/public/subscription-plans*',
            // Lightweight status/user/facility queries to render navigation and lockout pages
            'api/my-subscriptions',
            'api/subscription-status',
            'api/user',
            'api/my-facilities',
            // Subscribe/renewal flows (manual + PayMongo)
            'api/subscribe*',
            'api/manual-subscription-activate',
            'api/cancel-pending-subscription',
            'api/subscriptions/*/receipt*',
            'api/payments/paymongo/*',
            'api/subscriptions/expire-now',
            // Webhooks must not be gated by user subscription/auth
            'api/webhooks/paymongo*',
            'api/webhook',
            // Note: debug endpoints are intentionally NOT allowed while suspended.
        ];
        foreach ($allowedPatterns as $p) {
            if ($request->is($p)) {
                return $next($request);
            }
        }

        // If user is archived, block immediately
        if ($user->status && strtolower($user->status) === 'archived') {
            return response()->json([
                'success' => false,
                'message' => 'Subscription required. Your access is suspended until the center renews.'
            ], 403);
        }

        // Determine if the CENTER this user belongs to has an active subscription.
        // - Directors: check their own subscription
        // - Non-directors: check the subscription of the facility owner (director)
        $hasActiveAtCenter = false;
        if ($role === 'director') {
            $hasActiveAtCenter = $user->hasActiveSubscription();
        } else {
            try {
                if (!empty($user->financial_aid_id)) {
                    $facility = \App\Models\FinancialAid::find($user->financial_aid_id);
                    if ($facility && !empty($facility->user_id)) {
                        $director = \App\Models\User::find($facility->user_id);
                        if ($director) {
                            $hasActiveAtCenter = $director->hasActiveSubscription();
                        }
                    }
                }
            } catch (\Throwable $e) {
                // If anything goes wrong determining center subscription, default to blocking
                $hasActiveAtCenter = false;
            }
        }

        if (!$hasActiveAtCenter) {
            return response()->json([
                'success' => false,
                'message' => 'Center subscription required. Access is suspended until the director renews the subscription.'
            ], 403);
        }

        return $next($request);
    }
}
