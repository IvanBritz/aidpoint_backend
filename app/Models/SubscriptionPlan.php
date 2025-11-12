<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $table = 'subscription_plan';
    protected $primaryKey = 'plan_id';

    protected $fillable = [
        'plan_name',
        'price',
        'duration_in_months',
        'duration_in_days',
        'duration_in_seconds',
        'description',
        'is_free_trial',
        'trial_seconds',
        'archived',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_in_months' => 'integer',
        'duration_in_days' => 'integer',
        'duration_in_seconds' => 'integer',
        'is_free_trial' => 'boolean',
        'trial_seconds' => 'integer',
        'archived' => 'boolean',
    ];

    // Relationships
    public function financialAidSubscriptions()
    {
        return $this->hasMany(FinancialAidSubscription::class, 'plan_id', 'plan_id');
    }

    public function oldTransactions()
    {
        return $this->hasMany(SubscriptionTransaction::class, 'old_plan_id', 'plan_id');
    }

    public function newTransactions()
    {
        return $this->hasMany(SubscriptionTransaction::class, 'new_plan_id', 'plan_id');
    }

    /**
     * Check if this is a valid free trial plan
     */
    public function isValidFreeTrial(): bool
    {
        return $this->is_free_trial && $this->trial_seconds > 0;
    }

    /**
     * Check if a user is eligible for this free trial plan
     */
    public function isUserEligible(User $user): bool
    {
        if (!$this->isValidFreeTrial()) {
            return false;
        }

        // Must be a director
        $role = strtolower(optional($user->systemRole)->name);
        if ($role !== 'director') {
            return false;
        }

        // Must not have used this trial before
        return !SubscriptionTransaction::where('user_id', $user->id)
            ->where('new_plan_id', $this->plan_id)
            ->where('payment_method', 'FREE_TRIAL')
            ->exists();
    }

    /**
     * Check if this subscription plan has 0 duration (access duration equals 0 seconds)
     */
    public function hasZeroDuration(): bool
    {
        // For free trials, check trial_seconds
        if ($this->is_free_trial) {
            return $this->trial_seconds === 0;
        }
        
        // For regular plans, check if duration_in_months is 0
        return $this->duration_in_months === 0;
    }
}
