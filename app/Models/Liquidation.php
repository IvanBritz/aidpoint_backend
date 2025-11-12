<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Liquidation extends Model
{
    use HasFactory;

    protected $fillable = [
        'disbursement_id',
        'beneficiary_id',
        'liquidation_date',
        'disbursement_type',
        'or_invoice_no',
        'total_disbursed_amount',
        'total_receipt_amount',
        'remaining_amount',
        'is_complete',
        'completed_at',
        'description',
        'status',
        'reviewed_by',
        'reviewer_notes',
        'reviewed_at',
        'caseworker_approved_by',
        'caseworker_notes',
        'caseworker_approved_at',
        'finance_approved_by',
        'finance_notes',
        'finance_approved_at',
        'director_approved_by',
        'director_notes',
        'director_approved_at',
        'rejected_at_level',
        'rejection_reason',
    ];

    protected $casts = [
        'liquidation_date' => 'date',
        'total_disbursed_amount' => 'decimal:2',
        'total_receipt_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'is_complete' => 'boolean',
        'completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'caseworker_approved_at' => 'datetime',
        'finance_approved_at' => 'datetime',
        'director_approved_at' => 'datetime',
    ];

    // Relationships
    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class);
    }

    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function caseworkerApprover()
    {
        return $this->belongsTo(User::class, 'caseworker_approved_by');
    }

    public function financeApprover()
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    public function directorApprover()
    {
        return $this->belongsTo(User::class, 'director_approved_by');
    }

    public function receipts()
    {
        return $this->hasMany(LiquidationReceipt::class);
    }

    // Scopes for role-based queries
    public function scopeForBeneficiary($query, $beneficiaryId)
    {
        return $query->where('beneficiary_id', $beneficiaryId);
    }

    public function scopeForCaseworker($query, $caseworkerId)
    {
        return $query->whereHas('beneficiary', function ($q) use ($caseworkerId) {
            $q->where('caseworker_id', $caseworkerId);
        });
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopePendingCaseworkerApproval($query)
    {
        return $query->where('status', 'pending_caseworker_approval');
    }

    public function scopePendingFinanceApproval($query)
    {
        return $query->where('status', 'pending_finance_approval');
    }

    public function scopePendingDirectorApproval($query)
    {
        return $query->where('status', 'pending_director_approval');
    }

    // Helper methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isApproved()
    {
        return $this->status === 'approved';
    }

    public function isRejected()
    {
        return $this->status === 'rejected';
    }

    public function isPendingCaseworkerApproval()
    {
        return $this->status === 'pending_caseworker_approval';
    }

    public function isPendingFinanceApproval()
    {
        return $this->status === 'pending_finance_approval';
    }

    public function isPendingDirectorApproval()
    {
        return $this->status === 'pending_director_approval';
    }

    // Multi-level approval workflow methods
    public function submitForApproval()
    {
        if ($this->status === 'complete') {
            $this->update([
                'status' => 'pending_caseworker_approval'
            ]);
        }
    }

    public function approveByCaseworker($caseworkerId, $notes = null)
    {
        if ($this->status === 'pending_caseworker_approval') {
            $this->update([
                'status' => 'pending_finance_approval',
                'caseworker_approved_by' => $caseworkerId,
                'caseworker_notes' => $notes,
                'caseworker_approved_at' => now(),
            ]);
        }
    }

    public function approveByFinance($financeId, $notes = null)
    {
        if ($this->status === 'pending_finance_approval') {
            $this->update([
                'status' => 'pending_director_approval',
                'finance_approved_by' => $financeId,
                'finance_notes' => $notes,
                'finance_approved_at' => now(),
            ]);
        }
    }

    public function approveByDirector($directorId, $notes = null)
    {
        if ($this->status === 'pending_director_approval') {
            $this->update([
                'status' => 'approved',
                'director_approved_by' => $directorId,
                'director_notes' => $notes,
                'director_approved_at' => now(),
            ]);
            
            // Update disbursement liquidation status when liquidation is fully approved
            if ($this->disbursement) {
                $this->disbursement->updateLiquidationStatus();
            }
        }
    }

    public function rejectAtCaseworkerLevel($caseworkerId, $reason)
    {
        $this->update([
            'status' => 'rejected',
            'caseworker_approved_by' => $caseworkerId,
            'caseworker_notes' => $reason,
            'caseworker_approved_at' => now(),
            'rejected_at_level' => 'caseworker',
            'rejection_reason' => $reason,
        ]);
        
        // Update disbursement liquidation status when liquidation is rejected
        if ($this->disbursement) {
            $this->disbursement->updateLiquidationStatus();
        }
    }

    public function rejectAtFinanceLevel($financeId, $reason)
    {
        $this->update([
            'status' => 'rejected',
            'finance_approved_by' => $financeId,
            'finance_notes' => $reason,
            'finance_approved_at' => now(),
            'rejected_at_level' => 'finance',
            'rejection_reason' => $reason,
        ]);
        
        // Update disbursement liquidation status when liquidation is rejected
        if ($this->disbursement) {
            $this->disbursement->updateLiquidationStatus();
        }
    }

    public function rejectAtDirectorLevel($directorId, $reason)
    {
        $this->update([
            'status' => 'rejected',
            'director_approved_by' => $directorId,
            'director_notes' => $reason,
            'director_approved_at' => now(),
            'rejected_at_level' => 'director',
            'rejection_reason' => $reason,
        ]);
        
        // Update disbursement liquidation status when liquidation is rejected
        if ($this->disbursement) {
            $this->disbursement->updateLiquidationStatus();
        }
    }

    // Legacy methods for backward compatibility
    public function approve($reviewerId, $notes = null)
    {
        // This method is kept for backward compatibility
        // In the new workflow, this should not be used directly
        $this->update([
            'status' => 'approved',
            'reviewed_by' => $reviewerId,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);
        
        // Update disbursement liquidation status when liquidation is approved
        if ($this->disbursement) {
            $this->disbursement->updateLiquidationStatus();
        }
    }

    public function reject($reviewerId, $notes)
    {
        // This method is kept for backward compatibility
        // In the new workflow, this should not be used directly
        $this->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);
        
        // Update disbursement liquidation status when liquidation is rejected
        // This ensures the disbursement status reflects current approved liquidations only
        if ($this->disbursement) {
            $this->disbursement->updateLiquidationStatus();
        }
    }

    // Amount tracking methods
    public function updateReceiptTotals()
    {
        $totalReceiptAmount = $this->receipts()->sum('receipt_amount');
        $remainingAmount = $this->total_disbursed_amount - $totalReceiptAmount;
        $isComplete = $remainingAmount <= 0.01; // Account for floating point precision
        
        // Determine new status based on completion and current status
        $newStatus = $this->status;
        if ($isComplete && in_array($this->status, ['pending', 'in_progress'])) {
            $newStatus = 'complete';
        }
        
        $this->update([
            'total_receipt_amount' => $totalReceiptAmount,
            'remaining_amount' => max(0, $remainingAmount),
            'is_complete' => $isComplete,
            'completed_at' => $isComplete && !$this->completed_at ? now() : $this->completed_at,
            'status' => $newStatus,
        ]);
        
        return $this;
    }

    public function getCompletionPercentage()
    {
        if ($this->total_disbursed_amount <= 0) {
            return 0;
        }
        
        return min(100, ($this->total_receipt_amount / $this->total_disbursed_amount) * 100);
    }

    public function canAddMoreReceipts()
    {
        return $this->remaining_amount > 0.01 && !$this->isApproved() && !$this->isRejected();
    }

    public function isInProgress()
    {
        return $this->status === 'in_progress';
    }

    public function isComplete()
    {
        return $this->status === 'complete' || $this->is_complete;
    }
}