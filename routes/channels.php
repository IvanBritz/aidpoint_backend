<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private channel for user notifications
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('facility.{facilityId}.audit', function ($user, $facilityId) {
    $role = strtolower($user->systemRole->name ?? '');
    $ownsFacility = \App\Models\FinancialAid::where('id', (int) $facilityId)->where('user_id', $user->id)->exists();
    if ($ownsFacility) return true;
    if ((int) $user->financial_aid_id === (int) $facilityId && in_array($role, ['director', 'finance', 'caseworker', 'admin'])) {
        return true;
    }
    return false;
});
