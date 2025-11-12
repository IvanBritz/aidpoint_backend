<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\FinancialAid;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DirectorNotificationService
{
    /**
     * Notify directors about a liquidation needing final (director) approval.
     */
    public static function notifyNewLiquidationApproval($liquidation): void
    {
        $facilityId = $liquidation->beneficiary->financial_aid_id ?? null;
        $directors = static::getDirectorsForFacility($facilityId);
        if ($directors->isEmpty()) return;

        $beneficiaryName = trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? ''));
        $amount = (float) ($liquidation->total_disbursed_amount ?? $liquidation->total_receipt_amount ?? 0);

        foreach ($directors as $director) {
            Notification::createForUser(
                $director->id,
                'liquidation_pending',
                'Liquidation Needs Final Approval',
                "Liquidation from {$beneficiaryName} is pending your approval. Amount: ₱" . number_format($amount, 2),
                [
                    'liquidation_id' => $liquidation->id,
                    'beneficiary_id' => $liquidation->beneficiary_id,
                    'beneficiary_name' => $beneficiaryName,
                    'amount' => $amount,
                    'disbursement_type' => $liquidation->disbursement_type,
                    'action_link' => url('/director-liquidation')
                ],
                'high',
                'financial'
            );
        }
    }

    /**
     * Notify directors about finance-approved aid requests that require final approval.
     */
    public static function notifyPendingApproval($aidRequest): void
    {
        $facilityId = $aidRequest->beneficiary->financial_aid_id ?? null;
        $directors = static::getDirectorsForFacility($facilityId);
        if ($directors->isEmpty()) return;

        $beneficiaryName = trim(($aidRequest->beneficiary->firstname ?? '') . ' ' . ($aidRequest->beneficiary->lastname ?? ''));
        $amount = (float) ($aidRequest->approved_amount ?? $aidRequest->amount ?? 0);

        foreach ($directors as $director) {
            Notification::createForUser(
                $director->id,
                'director_pending_fund_request',
                'Fund Request Needs Final Approval',
                "Fund request from {$beneficiaryName} is pending your approval. Amount: ₱" . number_format($amount, 2),
                [
                    'aid_request_id' => $aidRequest->id,
                    'beneficiary_id' => $aidRequest->beneficiary_id,
                    'beneficiary_name' => $beneficiaryName,
                    'amount' => $amount,
                    'fund_type' => $aidRequest->fund_type,
                    'action_link' => url('/director-pending-fund-requests')
                ],
                'high',
                'financial'
            );
        }
    }

    /**
     * Notify directors when a liquidation is fully completed (director approved).
     */
    public static function notifyLiquidationCompleted($liquidation): void
    {
        $facilityId = $liquidation->beneficiary->financial_aid_id ?? null;
        $directors = static::getDirectorsForFacility($facilityId);
        if ($directors->isEmpty()) return;

        $beneficiaryName = trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? ''));
        $amount = (float) ($liquidation->total_disbursed_amount ?? 0);

        foreach ($directors as $director) {
            Notification::createForUser(
                $director->id,
                'liquidation_completed',
                'Liquidation Completed',
                "Liquidation for {$beneficiaryName} has been fully approved.",
                [
                    'liquidation_id' => $liquidation->id,
                    'beneficiary_id' => $liquidation->beneficiary_id,
                    'beneficiary_name' => $beneficiaryName,
                    'amount' => $amount,
                    'action_link' => url('/liquidation-completed')
                ],
                'medium',
                'financial'
            );
        }
    }

    /**
     * Check and notify directors about subscriptions expiring within a week.
     */
    public static function checkAndNotifyExpiringSubscriptions(): void
    {
        $sevenDaysFromNow = Carbon::now()->addDays(7)->toDateString();

        $expiringSubscriptions = DB::table('financial_aid_subscription')
            ->where('status', 'Active')
            ->whereDate('end_date', '=', $sevenDaysFromNow)
            ->get();

        foreach ($expiringSubscriptions as $subscription) {
            $user = User::find($subscription->user_id);
            if (!$user) continue;

            $plan = DB::table('subscription_plan')
                ->where('plan_id', $subscription->plan_id)
                ->first();
            $planName = $plan->plan_name ?? 'Unknown Plan';

            $directors = static::getDirectorsForFacility($user->financial_aid_id ?? null);
            foreach ($directors as $director) {
                // Idempotent by subscription
                $exists = Notification::where('user_id', $director->id)
                    ->where('type', 'subscription_expiring')
                    ->where('data->subscription_id', $subscription->subscription_id)
                    ->first();
                if ($exists) continue;

                Notification::createForUser(
                    $director->id,
                    'subscription_expiring',
                    'Subscription Expiring Soon',
                    "The subscription ({$planName}) will expire on " . Carbon::parse($subscription->end_date)->format('F j, Y') . ".",
                    [
                        'subscription_id' => $subscription->subscription_id,
                        'plan_id' => $subscription->plan_id,
                        'plan_name' => $planName,
                        'end_date' => $subscription->end_date,
                        'action_link' => url('/subscription')
                    ],
                    'high',
                    'financial'
                );
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int,\App\Models\User> */
    protected static function getDirectorsForFacility($facilityId = null)
    {
        // Base: users with role=director (optionally scoped by financial_aid_id)
        $directors = User::whereHas('systemRole', fn ($q) => $q->where('name', 'director'))
            ->when($facilityId, fn ($q) => $q->where('financial_aid_id', $facilityId))
            ->get();

        // Many installs keep director.financial_aid_id NULL and only relate via FinancialAid.owner (user_id)
        if ($facilityId) {
            $facility = FinancialAid::find($facilityId);
            if ($facility) {
                $owner = User::find($facility->user_id);
                if ($owner) {
                    // Ensure owner is included even if their financial_aid_id is null
                    if (!$directors->firstWhere('id', $owner->id)) {
                        $directors->push($owner);
                    }
                }
            }
        }

        return $directors->unique('id')->values();
    }

    public static function getAllDirectors()
    {
        return User::whereHas('systemRole', fn ($q) => $q->where('name', 'director'))->get();
    }
}
