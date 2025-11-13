<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Models\FinancialAidSubscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use App\Notifications\EmailVerificationCodeNotification;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): Response|JsonResponse
    {
        $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'systemrole_id' => ['required', 'exists:system_role,id'],
            'age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'enrolled_school' => ['nullable', 'string', 'max:200'],
            'school_year' => ['nullable', 'string', 'max:50'],
        ]);

        $user = User::create([
            'firstname' => $request->firstname,
            'middlename' => $request->middlename,
            'lastname' => $request->lastname,
            'contact_number' => $request->contact_number,
            'address' => $request->address,
            'email' => $request->email,
            'password' => Hash::make($request->string('password')),
            'status' => 'active',
            'systemrole_id' => $request->systemrole_id,
            'age' => $request->age,
            'enrolled_school' => $request->enrolled_school,
            'school_year' => $request->school_year,
            // email_verified_at remains null until verification
        ]);

        // Auto-subscribe director accounts to the Free plan (only once per user)
        try {
            $role = SystemRole::find($user->systemrole_id);
            if ($role && strtolower($role->name) === 'director') {
                // Find the Free plan first
                $freePlan = SubscriptionPlan::whereRaw('LOWER(plan_name) = ?', ['free'])->first();
                if ($freePlan) {
                    // Check if user has EVER had a free plan (active, expired, or pending)
                    $hasHadFreePlan = FinancialAidSubscription::where('user_id', $user->id)
                        ->where('plan_id', $freePlan->plan_id)
                        ->exists();
                    
                    if (!$hasHadFreePlan) {
                        // User has never had a free plan, give them one
                        $start = Carbon::now()->startOfDay();
                        $end = (clone $start)->addMonths((int) $freePlan->duration_in_months);

                        FinancialAidSubscription::create([
                            'user_id' => $user->id,
                            'plan_id' => $freePlan->plan_id,
                            'start_date' => $start->toDateString(),
                            'end_date' => $end->toDateString(),
                            'status' => 'Active',
                        ]);
                    }
                    // If user has already had a free plan (even if expired), they don't get another one
                }
            }
        } catch (\Throwable $e) {
            // Swallow errors to avoid breaking registration; consider logging in production
        }

        $roleName = strtolower(optional(SystemRole::find($user->systemrole_id))->name ?? '');

        if ($roleName === 'director') {
            // Issue 5-digit code and ask to verify before proceeding
            $code = $user->issueEmailVerificationCode();
            $user->notify(new EmailVerificationCodeNotification($code));
            // Do not log in yet; client will navigate to verification screen
            return response()->json([
                'message' => 'Verification required. A 5-digit code was sent to your email.',
                'requires_verification' => true,
                'email' => $user->email,
            ], 202);
        }

        Auth::login($user);

        return response()->noContent();
    }
}
