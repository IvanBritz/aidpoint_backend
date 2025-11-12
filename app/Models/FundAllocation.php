<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FundAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'financial_aid_id',
        'fund_type',
        'sponsor_name',
        'allocated_amount',
        'utilized_amount',
        'remaining_amount',
        'description',
        'is_active',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'utilized_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function financialAid()
    {
        return $this->belongsTo(FinancialAid::class, 'financial_aid_id');
    }
    
    // Legacy relationship for backwards compatibility
    public function user()
    {
        // Returns the director of the facility
        return $this->hasOneThrough(
            User::class,
            FinancialAid::class,
            'id',
            'id',
            'financial_aid_id',
            'user_id'
        );
    }

    // Helper methods
    public function updateRemainingAmount()
    {
        $this->remaining_amount = $this->allocated_amount - $this->utilized_amount;
        $this->save();
    }

    public function getUtilizationPercentageAttribute()
    {
        if ($this->allocated_amount == 0) return 0;
        return round(($this->utilized_amount / $this->allocated_amount) * 100, 2);
    }

    public function isOverUtilized()
    {
        return $this->utilized_amount > $this->allocated_amount;
    }
}
