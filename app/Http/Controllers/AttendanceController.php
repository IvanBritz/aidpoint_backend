<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryAttendance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    /**
     * Get attendance records for caseworker's assigned beneficiaries
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can view attendance records.'], 403);
        }

        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);
        $perPage = $request->get('per_page', 15);

        $query = BeneficiaryAttendance::with(['beneficiary'])
                    ->whereHas('beneficiary', function ($q) use ($user) {
                        $q->where('caseworker_id', $user->id);
                    })
                    ->forMonth($year, $month)
                    ->orderBy('attendance_date', 'desc');

        $records = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $records]);
    }

    /**
     * Get monthly attendance summary for a specific beneficiary
     */
    public function getBeneficiaryMonthlyAttendance(Request $request, $beneficiaryId)
    {
        $user = Auth::user();
        
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can view attendance records.'], 403);
        }

        // Verify beneficiary is assigned to this caseworker
        $beneficiary = User::where('id', $beneficiaryId)
                          ->where('caseworker_id', $user->id)
                          ->whereHas('systemRole', function ($q) {
                              $q->where('name', 'beneficiary');
                          })
                          ->first();

        if (!$beneficiary) {
            return response()->json(['success' => false, 'message' => 'Beneficiary not found or not assigned to you.'], 404);
        }

        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);

        $summary = BeneficiaryAttendance::getMonthlyAttendanceSummary($beneficiaryId, $year, $month);
        $colaDeduction = BeneficiaryAttendance::calculateColaDeduction($beneficiaryId, $year, $month);
        
        // Get individual attendance records for the month
        $attendanceRecords = BeneficiaryAttendance::forBeneficiary($beneficiaryId)
                                                 ->forMonth($year, $month)
                                                 ->orderBy('attendance_date')
                                                 ->get();
        
        // Calculate COLA amount based on scholarship status
        $baseColaAmount = $beneficiary->is_scholar ? 2000 : 1500;
        $finalColaAmount = max(0, $baseColaAmount - $colaDeduction);

        return response()->json([
            'success' => true,
            'data' => $attendanceRecords->map(function ($record) {
                return [
                    'id' => $record->id,
                    'attendance_date' => $record->attendance_date->format('Y-m-d'),
                    'day_of_week' => $record->day_of_week,
                    'status' => $record->status,
                    'notes' => $record->notes,
                ];
            }),
            'meta' => [
                'beneficiary' => $beneficiary,
                'period' => ['month' => $month, 'year' => $year],
                'attendance_summary' => $summary,
                'cola_calculation' => [
                    'base_amount' => $baseColaAmount,
                    'deduction_amount' => $colaDeduction,
                    'final_amount' => $finalColaAmount,
                    'is_scholar' => $beneficiary->is_scholar,
                ]
            ]
        ]);
    }

    /**
     * Record attendance for a beneficiary
     */
    public function recordAttendance(Request $request)
    {
        $user = Auth::user();
        
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can record attendance.'], 403);
        }

        $request->validate([
            'beneficiary_id' => ['required', 'integer', 'exists:users,id'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,excused'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Verify beneficiary is assigned to this caseworker
        $beneficiary = User::where('id', $request->beneficiary_id)
                          ->where('caseworker_id', $user->id)
                          ->whereHas('systemRole', function ($q) {
                              $q->where('name', 'beneficiary');
                          })
                          ->first();

        if (!$beneficiary) {
            return response()->json(['success' => false, 'message' => 'Beneficiary not found or not assigned to you.'], 404);
        }

        try {
            $attendance = BeneficiaryAttendance::createFromDate(
                $request->beneficiary_id,
                $request->attendance_date,
                $request->status,
                $user->id,
                $request->notes
            );
            
            // Automatically recalculate COLA amounts for this beneficiary's pending requests
            \App\Models\AidRequest::recalculateColaAmounts(
                $request->beneficiary_id,
                $attendance->year,
                $attendance->month
            );

            return response()->json([
                'success' => true,
                'data' => $attendance->load(['beneficiary', 'recordedBy']),
                'message' => 'Attendance recorded successfully.'
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance for this beneficiary on this date has already been recorded.'
                ], 422);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to record attendance.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update existing attendance record
     */
    public function updateAttendance(Request $request, $id)
    {
        $user = Auth::user();
        
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can update attendance.'], 403);
        }

        $request->validate([
            'status' => ['required', 'in:present,absent,excused'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $attendance = BeneficiaryAttendance::with(['beneficiary'])
                                          ->whereHas('beneficiary', function ($q) use ($user) {
                                              $q->where('caseworker_id', $user->id);
                                          })
                                          ->findOrFail($id);

        $attendance->update([
            'status' => $request->status,
            'notes' => $request->notes,
        ]);
        
        // Automatically recalculate COLA amounts for this beneficiary's pending requests
        \App\Models\AidRequest::recalculateColaAmounts(
            $attendance->beneficiary_id,
            $attendance->year,
            $attendance->month
        );

        return response()->json([
            'success' => true,
            'data' => $attendance->load(['beneficiary', 'recordedBy']),
            'message' => 'Attendance updated successfully.'
        ]);
    }

    /**
     * Get caseworker's assigned beneficiaries for attendance management
     */
    public function getAssignedBeneficiaries()
    {
        $user = Auth::user();
        
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can view assigned beneficiaries.'], 403);
        }

        $beneficiaries = User::with('systemRole')
                            ->where('caseworker_id', $user->id)
                            ->whereHas('systemRole', function ($q) {
                                $q->where('name', 'beneficiary');
                            })
                            ->where('status', 'active')
                            ->orderBy('firstname')
                            ->orderBy('lastname')
                            ->get()
                            ->map(function ($beneficiary) {
                                return [
                                    'id' => $beneficiary->id,
                                    'firstname' => $beneficiary->firstname,
                                    'lastname' => $beneficiary->lastname,
                                    'name' => $beneficiary->firstname . ' ' . $beneficiary->lastname,
                                    'email' => $beneficiary->email,
                                    'is_scholar' => $beneficiary->is_scholar,
                                    'enrolled_school' => $beneficiary->enrolled_school,
                                ];
                            });

        return response()->json(['success' => true, 'data' => $beneficiaries]);
    }

    /**
     * Calculate COLA amount for a beneficiary based on attendance
     */
    public function calculateColaAmount(Request $request)
    {
        $user = Auth::user();
        
        // Allow beneficiaries to check their own COLA or caseworkers to check for assigned beneficiaries
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $request->validate([
            'beneficiary_id' => ['required', 'integer', 'exists:users,id'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2020'],
        ]);

        $beneficiaryId = $request->beneficiary_id;
        $month = $request->month;
        $year = $request->year;

        // Authorization check
        $userRole = strtolower($user->systemRole->name);
        if ($userRole === 'beneficiary') {
            if ($user->id != $beneficiaryId) {
                return response()->json(['success' => false, 'message' => 'You can only check your own COLA amount.'], 403);
            }
        } elseif ($userRole === 'caseworker') {
            $beneficiary = User::where('id', $beneficiaryId)->where('caseworker_id', $user->id)->first();
            if (!$beneficiary) {
                return response()->json(['success' => false, 'message' => 'Beneficiary not assigned to you.'], 403);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $beneficiary = User::findOrFail($beneficiaryId);
        
        $summary = BeneficiaryAttendance::getMonthlyAttendanceSummary($beneficiaryId, $year, $month);
        $colaDeduction = BeneficiaryAttendance::calculateColaDeduction($beneficiaryId, $year, $month);
        
        $baseColaAmount = $beneficiary->is_scholar ? 2000 : 1500;
        $finalColaAmount = max(0, $baseColaAmount - $colaDeduction);

        return response()->json([
            'success' => true,
            'data' => [
                'beneficiary_name' => $beneficiary->firstname . ' ' . $beneficiary->lastname,
                'period' => ['month' => $month, 'year' => $year],
                'is_scholar' => $beneficiary->is_scholar,
                'base_amount' => $baseColaAmount,
                'sunday_absences' => $summary['sunday_absences'],
                'deduction_amount' => $colaDeduction,
                'final_cola_amount' => $finalColaAmount,
                'attendance_summary' => $summary,
            ]
        ]);
    }
}