<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAidSubscription extends Model
{
    use HasFactory;

    protected $table = 'financial_aid_subscription';
    protected $primaryKey = 'subscription_id';

    protected $fillable = [
        'user_id',
        'plan_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id', 'plan_id');
    }

    // Helper methods
    public function isActive()
    {
        return $this->status === 'Active' && $this->end_date >= now()->toDateString();
    }

    public function isExpired()
    {
        return $this->status === 'Expired' || $this->end_date < now()->toDateString();
    }
}
