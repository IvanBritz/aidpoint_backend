<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AidRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'beneficiary_id',
        'fund_type',
        'amount',
        'purpose',
        'month',
        'year',
        // overall status reflects final decision (set by director)
        'status',
        // Caseworker review (existing columns reused)
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        // Stage & multi-stage fields
        'stage',
        'finance_decision',
        'finance_reviewed_by',
        'finance_reviewed_at',
        'finance_notes',
        'director_decision',
        'director_reviewed_by',
        'director_reviewed_at',
        'director_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'month' => 'integer',
        'year' => 'integer',
        'reviewed_at' => 'datetime',
        'finance_reviewed_at' => 'datetime',
        'director_reviewed_at' => 'datetime',
    ];

    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function reviewer()
    {
        // Caseworker reviewer
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function financeReviewer()
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
    }

    public function directorReviewer()
    {
        return $this->belongsTo(User::class, 'director_reviewed_by');
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'aid_request_id');
    }
    
    /**
     * Recalculate COLA amounts for pending requests of a beneficiary
     * This should be called whenever attendance records are updated
     */
    public static function recalculateColaAmounts($beneficiaryId, $year = null, $month = null)
    {
        $query = self::where('beneficiary_id', $beneficiaryId)
                    ->where('fund_type', 'cola')
                    ->where('status', 'pending');
        
        // If specific month/year provided, only update those requests
        if ($year && $month) {
            $query->where('year', $year)->where('month', $month);
        }
        
        $requests = $query->with('beneficiary')->get();
        
        foreach ($requests as $request) {
            if ($request->beneficiary) {
                $requestMonth = $request->month ?: now()->month;
                $requestYear = $request->year ?: now()->year;
                
                $baseAmount = $request->beneficiary->is_scholar ? 2000 : 1500;
                $deduction = \App\Models\BeneficiaryAttendance::calculateColaDeduction($beneficiaryId, $requestYear, $requestMonth);
                $newAmount = max(0, $baseAmount - $deduction);
                
                // Only update if amount has changed
                if ($request->amount != $newAmount) {
                    $request->amount = $newAmount;
                    $request->save();
                }
            }
        }
        
        return $requests->count();
    }
}
