<?php

namespace App\Services;

use App\Models\FinancialAid;
use App\Models\FinancialAidSubscription;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccessRevocationService
{
    /**
     * Revoke access for a director whose subscription elapsed (0s left),
     * removing the director and all users tied to their center (financial_aid_id).
     * Sends notifications prior to deletion.
     */
    public function revokeCenterAccessForDirector(User $director, string $reason = 'Subscription expired and access duration reached 0 seconds'): void
    {
        try {
            // Determine the director's centers (facilities)
            $facilityIds = FinancialAid::where('user_id', $director->id)->pluck('id');

            // Load users associated to these facilities
            $associatedUsers = collect();
            if ($facilityIds->isNotEmpty()) {
                $associatedUsers = User::whereIn('financial_aid_id', $facilityIds->all())
                    ->get();
            }

            $toDeleteUserIds = $associatedUsers->pluck('id')->values()->all();
            // Ensure we will also remove the director
            if (!in_array($director->id, $toDeleteUserIds, true)) {
                $toDeleteUserIds[] = $director->id;
            }

            // Send notifications BEFORE deletion
            foreach ($toDeleteUserIds as $uid) {
                Notification::createForUser(
                    $uid,
                    'subscription_expired',
                    'Subscription Expired',
                    'Your subscription has expired. Please renew to regain access to the system.',
                    ['reason' => $reason, 'action_link' => url('/app/subscriptions')],
                    'urgent',
                    'financial'
                );
            }

            // Remove users (and their tokens by FK/cleanup if configured)
            DB::transaction(function () use ($toDeleteUserIds) {
                // Delete Sanctum tokens first if present
                try {
                    DB::table('personal_access_tokens')->whereIn('tokenable_id', $toDeleteUserIds)->delete();
                } catch (\Throwable $e) {
                    // Non-fatal
                    Log::warning('Failed deleting tokens during access revocation', ['error' => $e->getMessage()]);
                }

                // Finally delete users
                User::whereIn('id', $toDeleteUserIds)->delete();
            });

            Log::info('Center access revoked and users removed due to subscription expiration', [
                'director_id' => $director->id,
                'deleted_user_ids' => $toDeleteUserIds,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to revoke center access on expiration', [
                'director_id' => $director->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
