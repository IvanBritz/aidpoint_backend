<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Disbursement extends Model
{
    use HasFactory;

    protected $fillable = [
        'aid_request_id',
        'amount',
        'liquidated_amount',
        'remaining_to_liquidate',
        'fully_liquidated',
        'fully_liquidated_at',
        'reference_no',
        'notes',
        'status',
        'finance_disbursed_by',
        'finance_disbursed_at',
        'caseworker_received_by',
        'caseworker_received_at',
        'caseworker_disbursed_by',
        'caseworker_disbursed_at',
        'beneficiary_received_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'liquidated_amount' => 'decimal:2',
        'remaining_to_liquidate' => 'decimal:2',
        'fully_liquidated' => 'boolean',
        'fully_liquidated_at' => 'datetime',
        'finance_disbursed_at' => 'datetime',
        'caseworker_received_at' => 'datetime',
        'caseworker_disbursed_at' => 'datetime',
        'beneficiary_received_at' => 'datetime',
    ];

    public function aidRequest()
    {
        return $this->belongsTo(AidRequest::class, 'aid_request_id');
    }

    public function financeDispenser()
    {
        return $this->belongsTo(User::class, 'finance_disbursed_by');
    }

    public function caseworkerReceiver()
    {
        return $this->belongsTo(User::class, 'caseworker_received_by');
    }

    public function caseworkerDispenser()
    {
        return $this->belongsTo(User::class, 'caseworker_disbursed_by');
    }

    // Scope for role-based queries
    public function scopeForFinance($query, $facilityId)
    {
        return $query->whereHas('aidRequest.beneficiary', function ($q) use ($facilityId) {
            $q->where('financial_aid_id', $facilityId);
        });
    }

    public function scopeForCaseworker($query, $caseworkerId)
    {
        return $query->whereHas('aidRequest.beneficiary', function ($q) use ($caseworkerId) {
            $q->where('caseworker_id', $caseworkerId);
        });
    }

    public function scopeForBeneficiary($query, $beneficiaryId)
    {
        return $query->whereHas('aidRequest', function ($q) use ($beneficiaryId) {
            $q->where('beneficiary_id', $beneficiaryId);
        });
    }

    // Liquidation tracking methods
    public function updateLiquidationStatus()
    {
        $totalLiquidated = $this->liquidations()->where('status', 'approved')->sum('total_receipt_amount');
        $remainingToLiquidate = max(0, $this->amount - $totalLiquidated);
        $fullyLiquidated = $remainingToLiquidate <= 0.01;
        
        $this->update([
            'liquidated_amount' => $totalLiquidated,
            'remaining_to_liquidate' => $remainingToLiquidate,
            'fully_liquidated' => $fullyLiquidated,
            'fully_liquidated_at' => $fullyLiquidated && !$this->fully_liquidated_at ? now() : $this->fully_liquidated_at,
        ]);
        
        return $this;
    }

    public function getLiquidationPercentage()
    {
        if ($this->amount <= 0) {
            return 0;
        }
        
        return min(100, ($this->liquidated_amount / $this->amount) * 100);
    }

    public function needsLiquidation()
    {
        return $this->status === 'beneficiary_received' && !$this->fully_liquidated;
    }

    // Relationship to liquidations
    public function liquidations()
    {
        return $this->hasMany(\App\Models\Liquidation::class);
    }
}