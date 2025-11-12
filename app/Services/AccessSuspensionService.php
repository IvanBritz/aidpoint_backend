<?php

namespace App\Services;

use App\Models\FinancialAid;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccessSuspensionService
{
    /**
     * Suspend access for the director and all users under their center.
     * - Marks users' status as "archived"
     * - Deletes active API tokens to immediately log them out
     * - Sends a renewal notification
     */
    public function suspendCenterAccessForDirector(User $director, string $reason = 'Subscription ended'): void
    {
        try {
            $facilityIds = FinancialAid::where('user_id', $director->id)->pluck('id');

            $affectedUsers = collect([$director]);
            if ($facilityIds->isNotEmpty()) {
                $affectedUsers = $affectedUsers->merge(
                    User::whereIn('financial_aid_id', $facilityIds->all())->get()
                );
            }

            $userIds = $affectedUsers->pluck('id')->all();

            DB::transaction(function () use ($affectedUsers, $userIds) {
                // Mark as archived (do not delete data)
                foreach ($affectedUsers as $user) {
                    $user->update(['status' => 'archived']);
                }

                // Remove active tokens to block access right away
                try {
                    DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->delete();
                } catch (\Throwable $e) {
                    Log::warning('Failed deleting tokens during suspension', ['error' => $e->getMessage()]);
                }
            });

            // Notify after suspension
            foreach ($userIds as $uid) {
                Notification::notifySubscriptionExpired($uid, [
                    'message' => 'Your access has been suspended and data archived. Renew to regain access.',
                    'reason' => $reason,
                    'action_link' => url('/app/subscriptions'),
                ]);
            }

            Log::info('Center access suspended and data archived due to subscription end', [
                'director_id' => $director->id,
                'user_ids' => $userIds,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to suspend center access', [
                'director_id' => $director->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restore access for the director and users under their center after renewal
     */
    public function restoreCenterAccessForDirector(User $director): void
    {
        try {
            $facilityIds = \App\Models\FinancialAid::where('user_id', $director->id)->pluck('id');

            $affectedUsers = collect([$director]);
            if ($facilityIds->isNotEmpty()) {
                $affectedUsers = $affectedUsers->merge(
                    \App\Models\User::whereIn('financial_aid_id', $facilityIds->all())->get()
                );
            }

            foreach ($affectedUsers as $user) {
                $user->update(['status' => 'active']);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to restore center access after renewal', [
                'director_id' => $director->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
