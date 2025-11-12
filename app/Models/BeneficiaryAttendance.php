<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class BeneficiaryAttendance extends Model
{
    use HasFactory;

    protected $table = 'beneficiary_attendance';

    protected $fillable = [
        'beneficiary_id',
        'recorded_by',
        'attendance_date',
        'day_of_week',
        'status',
        'notes',
        'month',
        'year',
    ];

    protected $casts = [
        'attendance_date' => 'date',
    ];

    // Relationships
    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // Helper methods
    public static function createFromDate($beneficiaryId, $date, $status = 'present', $recordedBy = null, $notes = null)
    {
        $carbonDate = Carbon::parse($date);
        
        return self::create([
            'beneficiary_id' => $beneficiaryId,
            'recorded_by' => $recordedBy,
            'attendance_date' => $carbonDate,
            'day_of_week' => strtolower($carbonDate->format('l')),
            'status' => $status,
            'notes' => $notes,
            'month' => $carbonDate->month,
            'year' => $carbonDate->year,
        ]);
    }

    public static function getSundayAbsencesForMonth($beneficiaryId, $year, $month)
    {
        try {
            return self::where('beneficiary_id', $beneficiaryId)
                       ->where('year', $year)
                       ->where('month', $month)
                       ->where('day_of_week', 'sunday')
                       ->where('status', 'absent')
                       ->count();
        } catch (\Exception $e) {
            // Return 0 if table doesn't exist or query fails
            return 0;
        }
    }

    public static function getMonthlyAttendanceSummary($beneficiaryId, $year, $month)
    {
        try {
            $records = self::where('beneficiary_id', $beneficiaryId)
                          ->where('year', $year)
                          ->where('month', $month)
                          ->get();

            return [
                'total_days' => $records->count(),
                'present_days' => $records->where('status', 'present')->count(),
                'absent_days' => $records->where('status', 'absent')->count(),
                'excused_days' => $records->where('status', 'excused')->count(),
                'sunday_absences' => $records->where('day_of_week', 'sunday')
                                            ->where('status', 'absent')
                                            ->count(),
            ];
        } catch (\Exception $e) {
            // Return default values if table doesn't exist or query fails
            return [
                'total_days' => 0,
                'present_days' => 0,
                'absent_days' => 0,
                'excused_days' => 0,
                'sunday_absences' => 0,
            ];
        }
    }

    public static function calculateColaDeduction($beneficiaryId, $year, $month)
    {
        try {
            $sundayAbsences = self::getSundayAbsencesForMonth($beneficiaryId, $year, $month);
            return $sundayAbsences * 300; // ₱300 per Sunday absence
        } catch (\Exception $e) {
            // Return 0 deduction if there's an error
            return 0;
        }
    }

    // Scopes
    public function scopeForBeneficiary($query, $beneficiaryId)
    {
        return $query->where('beneficiary_id', $beneficiaryId);
    }

    public function scopeForMonth($query, $year, $month)
    {
        return $query->where('year', $year)->where('month', $month);
    }

    public function scopeSundayAbsences($query)
    {
        return $query->where('day_of_week', 'sunday')->where('status', 'absent');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}