<?php

namespace App\Http\Controllers;

use App\Models\AidRequest;
use App\Models\BeneficiaryDocumentSubmission;
use App\Models\BeneficiaryAttendance;
use App\Models\Notification;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\DirectorNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AidRequestController extends Controller
{
    // Beneficiary: list my requests
    public function myRequests()
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json(['success' => false, 'message' => 'Only beneficiaries can view their aid requests.'], 403);
        }
        $items = AidRequest::where('beneficiary_id', $user->id)->orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    // Beneficiary: create a request (requires approved enrollment verification)
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json(['success' => false, 'message' => 'Only beneficiaries can create aid requests.'], 403);
        }
        $request->validate([
            'fund_type' => ['required', 'in:tuition,cola,other'],
            'amount' => ['required_unless:fund_type,cola', 'nullable', 'numeric', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            // Month/year are now derived from server time for COLA; ignore client-provided values
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:2000'],
        ]);

        // Gate: must have most recent submission approved
        $latest = BeneficiaryDocumentSubmission::where('beneficiary_id', $user->id)
            ->where('status', 'approved')
            ->orderByDesc('created_at')->first();
        if (!$latest) {
            return response()->json([
                'success' => false,
                'message' => 'Your enrollment verification must be approved before requesting aid.'
            ], 422);
        }

        // Update user's scholarship status from latest enrollment verification
        if ($user->is_scholar !== $latest->is_scholar) {
            $user->is_scholar = $latest->is_scholar;
            $user->save();
        }

        // Determine the period for the request
        $requestMonth = null;
        $requestYear = null;
        
        if (in_array($request->fund_type, ['cola', 'tuition'])) {
            if ($request->fund_type === 'cola') {
                // For COLA, use the SERVER'S current month/year
                $requestMonth = now()->month;
                $requestYear = now()->year;
            } else {
                // For tuition, use current month/year
                $requestMonth = now()->month;
                $requestYear = now()->year;
            }
            
            // Check for existing requests for the same month/year and fund type
            $existingRequest = AidRequest::where('beneficiary_id', $user->id)
                ->where('fund_type', $request->fund_type)
                ->where('month', $requestMonth)
                ->where('year', $requestYear)
                ->where('status', '!=', 'rejected') // Allow new requests if previous was rejected
                ->first();
                
            if ($existingRequest) {
                $monthName = \Carbon\Carbon::create($requestYear, $requestMonth, 1)->format('F Y');
                return response()->json([
                    'success' => false,
                    'message' => "You have already submitted a {$request->fund_type} request for {$monthName}. Each beneficiary can only request funds for {$request->fund_type} once per month."
                ], 422);
            }
        }
        
        // Calculate COLA amount if fund_type is 'cola'
        $finalAmount = $request->amount;
        $colaCalculation = null;
        
        if ($request->fund_type === 'cola') {
            // Build the 5-month window starting from the enrollment month
            $enrollmentDate = \Carbon\Carbon::parse($latest->enrollment_date);
            $allowed = [];
            for ($i = 0; $i < 5; $i++) {
                $d = $enrollmentDate->copy()->addMonths($i);
                $allowed[] = ['month' => $d->month, 'year' => $d->year];
            }
            // Current server month/year must be within the allowed 5-month window
            $selMonth = $requestMonth;
            $selYear = $requestYear;
            $isValid = false;
            foreach ($allowed as $a) {
                if ((int)$a['month'] === (int)$selMonth && (int)$a['year'] === (int)$selYear) { $isValid = true; break; }
            }
            if (!$isValid) {
                $monthName = \Carbon\Carbon::create($selYear, $selMonth, 1)->format('F Y');
                return response()->json([
                    'success' => false,
                    'message' => "COLA is not available for {$monthName}. Requests are only allowed for 5 months starting from your enrollment month.",
                ], 422);
            }
            $month = $selMonth;
            $year = $selYear;
            
            // Calculate base COLA amount based on scholarship status
            $baseAmount = $user->is_scholar ? 2000 : 1500;
            
            // Calculate deduction based on Sunday absences
            $deduction = BeneficiaryAttendance::calculateColaDeduction($user->id, $year, $month);
            
            // Final COLA amount cannot be negative
            $finalAmount = max(0, $baseAmount - $deduction);
            
            $colaCalculation = [
                'base_amount' => $baseAmount,
                'deduction_amount' => $deduction,
                'sunday_absences' => BeneficiaryAttendance::getSundayAbsencesForMonth($user->id, $year, $month),
                'is_scholar' => $user->is_scholar,
                'period' => ['month' => $month, 'year' => $year],
                'enrollment_date' => $latest->enrollment_date,
                'allowed_months' => $allowed,
            ];
            
            // If final amount is 0, don't allow the request
            if ($finalAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your COLA amount for this period is ₱0.00 due to attendance deductions. No request can be submitted.',
                    'cola_calculation' => $colaCalculation
                ], 422);
            }
            
            // Re-check for existing requests if month/year was changed during COLA validation
            if ($request->fund_type === 'cola' && ($requestMonth != $request->month || $requestYear != $request->year)) {
                $existingRequest = AidRequest::where('beneficiary_id', $user->id)
                    ->where('fund_type', 'cola')
                    ->where('month', $requestMonth)
                    ->where('year', $requestYear)
                    ->where('status', '!=', 'rejected')
                    ->first();
                    
                if ($existingRequest) {
                    $monthName = \Carbon\Carbon::create($requestYear, $requestMonth, 1)->format('F Y');
                    return response()->json([
                        'success' => false,
                        'message' => "You have already submitted a COLA request for {$monthName}. Each beneficiary can only request COLA once per month."
                    ], 422);
                }
            }
        }

        $aid = AidRequest::create([
            'beneficiary_id' => $user->id,
            'fund_type' => $request->fund_type,
            'amount' => $finalAmount,
            'purpose' => $request->purpose ?: ($request->fund_type === 'cola' ? 'COLA allowance request' : null),
            'month' => $requestMonth,
            'year' => $requestYear,
            'status' => 'pending',
        ]);

        // Notify the assigned caseworker
        if ($user->caseworker_id) {
            Notification::notifyCaseworkerOfSubmission(
                $user->caseworker_id,
                $user->firstname . ' ' . $user->lastname,
                'aid'
            );
        }

        $responseData = ['aid_request' => $aid];
        if ($colaCalculation) {
            $responseData['cola_calculation'] = $colaCalculation;
        }

        try {
            AuditLog::logAidRequestSubmission($user->id, [
                'request_id' => $aid->id,
                'beneficiary_id' => $user->id,
                'beneficiary_name' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')),
                'fund_type' => $aid->fund_type,
                'amount' => (float) $aid->amount,
                'month' => $requestMonth,
                'year' => $requestYear,
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'success' => true,
            'data' => $responseData,
            'message' => 'Aid request submitted.'
        ], 201);
    }

    // Caseworker: pending requests for assigned beneficiaries
    public function pendingForCaseworker(Request $request)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can view pending aid requests.'], 403);
        }
        $perPage = $request->get('per_page', 10);
        $query = AidRequest::with(['beneficiary'])
            ->where('status', 'pending')
            ->where('stage', 'caseworker')
            ->whereHas('beneficiary', function ($q) use ($user) {
                $q->where('caseworker_id', $user->id);
            })
            ->orderByDesc('created_at');
        $page = $query->paginate($perPage);
        
        // For COLA requests, compute the current amount with latest attendance for display
        $page->setCollection(
            $page->getCollection()->map(function ($aid) {
                if ($aid->fund_type === 'cola' && $aid->beneficiary) {
                    $month = $aid->month ?: ($aid->created_at ? $aid->created_at->month : now()->month);
                    $year = $aid->year ?: ($aid->created_at ? $aid->created_at->year : now()->year);
                    $baseAmount = $aid->beneficiary->is_scholar ? 2000 : 1500;
                    $deduction = \App\Models\BeneficiaryAttendance::calculateColaDeduction($aid->beneficiary_id, $year, $month);
                    $currentAmount = max(0, $baseAmount - $deduction);
                    
                    // Update the amount in the database if it has changed
                    if ($aid->amount != $currentAmount) {
                        $aid->amount = $currentAmount;
                        $aid->save();
                    }
                }
                return $aid;
            })
        );
        
        return response()->json(['success' => true, 'data' => $page]);
    }

    // Caseworker: review a request
    public function review(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can review aid requests.'], 403);
        }
        $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $aid = AidRequest::with('beneficiary')->findOrFail($id);
        if (!$aid->beneficiary || $aid->beneficiary->caseworker_id !== $user->id) {
            try {
                AuditLog::logEvent(
                    'aid_request_caseworker_unauthorized_attempt',
                    'Unauthorized caseworker review attempt',
                    [
                        'aid_request_id' => $aid->id,
                        'attempted_by' => $user->id,
                        'beneficiary_id' => $aid->beneficiary?->id,
                    ],
                    'aid_request',
                    $aid->id,
                    'critical',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'You are not assigned to this beneficiary.'], 403);
        }
        if (!in_array($aid->stage, ['caseworker']) || $aid->status !== 'pending') {
            try {
                AuditLog::logEvent(
                    'aid_request_invalid_stage_attempt',
                    'Caseworker attempted review with invalid stage/status',
                    [
                        'aid_request_id' => $aid->id,
                        'current_stage' => $aid->stage,
                        'current_status' => $aid->status,
                    ],
                    'aid_request',
                    $aid->id,
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This request is not in the caseworker review stage.'], 422);
        }

        // Persist caseworker decision in the existing generic columns
        $aid->review_notes = $request->review_notes;
        $aid->reviewed_by = $user->id;
        $aid->reviewed_at = now();

        if ($request->status === 'approved') {
            // Move to finance stage; overall status remains pending until final approval
$aid->stage = 'finance';
            // Realtime notify finance of new pending fund request
            try {
                $facilityId = $aid->beneficiary?->financial_aid_id;
                if ($facilityId) {
                    $financeIds = User::whereHas('systemRole', function ($q) { $q->where('name', 'finance'); })
                        ->where('financial_aid_id', $facilityId)
                        ->pluck('id')->all();
                    if ($financeIds) {
                        Notification::notifyFinancePendingAidRequest($financeIds, [
                            'aid_request_id' => $aid->id,
                            'fund_type' => $aid->fund_type,
                            'amount' => (float) $aid->amount,
                            'beneficiary_name' => trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('notifyFinancePendingAidRequest failed', ['aid_id'=>$aid->id,'error'=>$e->getMessage()]);
            }
        } else {
            // Rejected at caseworker stage ends the workflow
            $aid->status = 'rejected';
            $aid->stage = 'done';
        }

        $aid->save();

        try {
            $reviewData = [
                'status' => $request->status,
                'type' => 'aid_request',
                'beneficiary_name' => trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')),
                'item_id' => $aid->id,
                'fund_type' => $aid->fund_type,
                'amount' => (float) $aid->amount,
                'review_notes' => $request->review_notes,
            ];
            AuditLog::logCaseworkerReview($reviewData);
        } catch (\Throwable $e) {
        }

        // Notify the beneficiary of the review result
        Notification::notifyBeneficiaryOfReview(
            $aid->beneficiary_id,
            'aid',
            $request->status,
            $user->firstname . ' ' . $user->lastname,
            $request->review_notes,
            $aid->amount
        );

        $msg = $request->status === 'approved' ? 'moved to finance review.' : 'rejected.';
        return response()->json(['success' => true, 'data' => $aid, 'message' => 'Aid request has been ' . $msg]);
    }

    // Finance: pending requests for staff in the same facility
    public function pendingForFinance(Request $request)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'finance') {
            return response()->json(['success' => false, 'message' => 'Only finance staff can view pending aid requests for finance review.'], 403);
        }
        $facilityId = $user->financial_aid_id;
        $perPage = $request->get('per_page', 10);

        $query = AidRequest::with(['beneficiary', 'reviewer'])
            ->where('status', 'pending')
            ->where('stage', 'finance')
            ->where('finance_decision', 'pending')
            ->whereHas('beneficiary', function ($q) use ($facilityId) {
                $q->where('financial_aid_id', $facilityId);
            })
            ->orderByDesc('created_at');

        $page = $query->paginate($perPage);
        // For COLA requests, compute the current amount with latest attendance for display
        $page->setCollection(
            $page->getCollection()->map(function ($aid) {
                if ($aid->fund_type === 'cola' && $aid->beneficiary) {
                    $month = $aid->month ?: ($aid->created_at ? $aid->created_at->month : now()->month);
                    $year = $aid->year ?: ($aid->created_at ? $aid->created_at->year : now()->year);
                    $baseAmount = $aid->beneficiary->is_scholar ? 2000 : 1500;
                    $deduction = \App\Models\BeneficiaryAttendance::calculateColaDeduction($aid->beneficiary_id, $year, $month);
                    $currentAmount = max(0, $baseAmount - $deduction);
                    
                    // Update the amount in the database if it has changed
                    if ($aid->amount != $currentAmount) {
                        $aid->amount = $currentAmount;
                        $aid->save();
                    }
                }
                // Flatten caseworker approver name for frontend convenience
                if ($aid->relationLoaded('reviewer') && $aid->reviewer) {
                    $aid->setAttribute('reviewer_name', $aid->reviewer->full_name);
                }
                return $aid;
            })
        );
        return response()->json(['success' => true, 'data' => $page]);
    }

    // Finance: review approve/reject
    public function financeReview(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'finance') {
            return response()->json(['success' => false, 'message' => 'Only finance staff can review aid requests.'], 403);
        }
        $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $aid = AidRequest::with('beneficiary')->findOrFail($id);
        if (!$aid->beneficiary || $aid->beneficiary->financial_aid_id !== $user->financial_aid_id) {
            return response()->json(['success' => false, 'message' => 'This request does not belong to your facility.'], 403);
        }
        if (!($aid->status === 'pending' && $aid->stage === 'finance' && $aid->finance_decision === 'pending')) {
            return response()->json(['success' => false, 'message' => 'This request is not in the finance review stage.'], 422);
        }

        $aid->finance_notes = $request->notes;
        $aid->finance_reviewed_by = $user->id;
        $aid->finance_reviewed_at = now();
        $aid->finance_decision = $request->status;

        if ($request->status === 'approved') {
            // If COLA, recalculate amount using latest attendance before moving forward
            if ($aid->fund_type === 'cola') {
                $month = $aid->month ?: ($aid->created_at ? $aid->created_at->month : now()->month);
                $year = $aid->year ?: ($aid->created_at ? $aid->created_at->year : now()->year);
                $baseAmount = $aid->beneficiary->is_scholar ? 2000 : 1500;
                $deduction = \App\Models\BeneficiaryAttendance::calculateColaDeduction($aid->beneficiary_id, $year, $month);
                $aid->amount = max(0, $baseAmount - $deduction);
            }
            $aid->stage = 'director';
            
            // Notify directors about pending approval
            try {
                DirectorNotificationService::notifyPendingApproval($aid->fresh('beneficiary'));
            } catch (\Exception $e) {
                \Log::warning('Failed to notify directors about aid request approval', [
                    'error' => $e->getMessage(),
                    'aid_request_id' => $aid->id,
                ]);
            }
        } else {
            $aid->status = 'rejected';
            $aid->stage = 'done';
        }

        $aid->save();

        try {
            $eventType = $request->status === 'approved' ? 'aid_request_finance_approved' : 'aid_request_finance_rejected';
            $desc = $request->status === 'approved'
                ? ('Finance approved aid request: ₱' . number_format((float) $aid->amount, 2) . ' for ' . trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')))
                : ('Finance rejected aid request for ' . trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')));
            AuditLog::logEvent(
                $eventType,
                $desc,
                [
                    'aid_request_id' => $aid->id,
                    'beneficiary_id' => $aid->beneficiary_id,
                    'beneficiary_name' => trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')),
                    'fund_type' => $aid->fund_type,
                    'amount' => (float) $aid->amount,
                    'notes' => $request->notes,
                ],
                'aid_request',
                $aid->id,
                $request->status === 'approved' ? 'high' : 'medium',
                'financial'
            );
        } catch (\Throwable $e) {
        }
        return response()->json(['success' => true, 'data' => $aid, 'message' => 'Finance review recorded.']);
    }

    // Director: pending requests for the director's facility
    public function pendingForDirector(Request $request)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'director') {
            return response()->json(['success' => false, 'message' => 'Only directors can view pending aid requests for final approval.'], 403);
        }
        
        // Directors own facilities, so find the facility they own
        $facility = \App\Models\FinancialAid::where('user_id', $user->id)
            ->where('isManagable', true) // Only approved facilities
            ->first();
            
        if (!$facility) {
            return response()->json(['success' => false, 'message' => 'No approved facility found for the current user.'], 404);
        }
        
        $facilityId = $facility->id;
        $perPage = $request->get('per_page', 10);

        $query = AidRequest::with(['beneficiary', 'reviewer', 'financeReviewer'])
            ->where('status', 'pending')
            ->where('stage', 'director')
            ->where('finance_decision', 'approved')
            ->where('director_decision', 'pending')
            ->whereHas('beneficiary', function ($q) use ($facilityId) {
                $q->where('financial_aid_id', $facilityId);
            })
            ->orderByDesc('created_at');

        $page = $query->paginate($perPage);
        // Recompute COLA amounts; also flatten approver names for frontend
        $page->setCollection(
            $page->getCollection()->map(function ($aid) {
                if ($aid->fund_type === 'cola' && $aid->beneficiary) {
                    $month = $aid->month ?: ($aid->created_at ? $aid->created_at->month : now()->month);
                    $year = $aid->year ?: ($aid->created_at ? $aid->created_at->year : now()->year);
                    $baseAmount = $aid->beneficiary->is_scholar ? 2000 : 1500;
                    $deduction = \App\Models\BeneficiaryAttendance::calculateColaDeduction($aid->beneficiary_id, $year, $month);
                    $currentAmount = max(0, $baseAmount - $deduction);
                    
                    // Update the amount in the database if it has changed
                    if ($aid->amount != $currentAmount) {
                        $aid->amount = $currentAmount;
                        $aid->save();
                    }
                }
                if ($aid->relationLoaded('reviewer') && $aid->reviewer) {
                    $aid->setAttribute('caseworker_name', $aid->reviewer->full_name);
                }
                if ($aid->relationLoaded('financeReviewer') && $aid->financeReviewer) {
                    $aid->setAttribute('finance_name', $aid->financeReviewer->full_name);
                }
                return $aid;
            })
        );
        return response()->json(['success' => true, 'data' => $page]);
    }

    // Director: final approval/rejection
    public function directorReview(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'director') {
            return response()->json(['success' => false, 'message' => 'Only directors can perform final approval.'], 403);
        }
        $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $aid = AidRequest::with('beneficiary')->findOrFail($id);
        
        // Directors own facilities, so find the facility they own
        $facility = \App\Models\FinancialAid::where('user_id', $user->id)
            ->where('isManagable', true)
            ->first();
            
        if (!$facility || !$aid->beneficiary || $aid->beneficiary->financial_aid_id !== $facility->id) {
            return response()->json(['success' => false, 'message' => 'This request does not belong to your facility.'], 403);
        }
        if (!($aid->status === 'pending' && $aid->stage === 'director' && $aid->director_decision === 'pending')) {
            return response()->json(['success' => false, 'message' => 'This request is not in the director review stage.'], 422);
        }

        $aid->director_notes = $request->notes;
        $aid->director_reviewed_by = $user->id;
        $aid->director_reviewed_at = now();
        $aid->director_decision = $request->status;

        if ($request->status === 'approved') {
            // Final guard: for COLA, recalculate one more time with latest attendance
            if ($aid->fund_type === 'cola') {
                $month = $aid->month ?: ($aid->created_at ? $aid->created_at->month : now()->month);
                $year = $aid->year ?: ($aid->created_at ? $aid->created_at->year : now()->year);
                $baseAmount = $aid->beneficiary->is_scholar ? 2000 : 1500;
                $deduction = \App\Models\BeneficiaryAttendance::calculateColaDeduction($aid->beneficiary_id, $year, $month);
                $aid->amount = max(0, $baseAmount - $deduction);
            }
$aid->status = 'approved';
            // Notify beneficiary that their request is fully approved (final)
            try {
                Notification::createForUser(
                    (int) $aid->beneficiary_id,
                    'aid_final_approved',
                    'Fund Request Approved',
                    'Your fund request has been approved by the director. Amount: ₱' . number_format((float) $aid->amount, 2) . '.',
                    [
                        'aid_request_id' => $aid->id,
                        'fund_type' => $aid->fund_type,
                        'amount' => (float) $aid->amount,
                        'action_link' => url('/request-fund')
                    ],
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) {
                \Log::warning('notify beneficiary final approval failed', ['aid_id' => $aid->id, 'error' => $e->getMessage()]);
            }
            // Realtime notify finance that this is ready for cash disbursement
            try {
                $facilityId = $aid->beneficiary?->financial_aid_id;
                if ($facilityId) {
                    $financeIds = User::whereHas('systemRole', function ($q) { $q->where('name', 'finance'); })
                        ->where('financial_aid_id', $facilityId)
                        ->pluck('id')->all();
                    if ($financeIds) {
                        Notification::notifyFinanceDisbursementReady($financeIds, [
                            'aid_request_id' => $aid->id,
                            'fund_type' => $aid->fund_type,
                            'amount' => (float) $aid->amount,
                            'beneficiary_name' => trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('notifyFinanceDisbursementReady failed', ['aid_id'=>$aid->id,'error'=>$e->getMessage()]);
            }
        } else {
            $aid->status = 'rejected';
            // Notify beneficiary of director rejection
            try {
                Notification::createForUser(
                    (int) $aid->beneficiary_id,
                    'aid_final_rejected',
                    'Fund Request Rejected',
                    'Your fund request was rejected by the director.' . ($request->notes ? ' Notes: ' . $request->notes : ''),
                    [
                        'aid_request_id' => $aid->id,
                        'fund_type' => $aid->fund_type,
                        'amount' => (float) $aid->amount,
                        'notes' => $request->notes,
                        'action_link' => url('/request-fund')
                    ],
                    'medium',
                    'financial'
                );
            } catch (\Throwable $e) {
                \Log::warning('notify beneficiary final rejection failed', ['aid_id' => $aid->id, 'error' => $e->getMessage()]);
            }
        }
        $aid->stage = 'done';
        $aid->save();

        try {
            $eventType = $request->status === 'approved' ? 'aid_request_director_approved' : 'aid_request_director_rejected';
            $desc = $request->status === 'approved'
                ? ('Director approved aid request: ₱' . number_format((float) $aid->amount, 2) . ' for ' . trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')))
                : ('Director rejected aid request for ' . trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')));
            AuditLog::logEvent(
                $eventType,
                $desc,
                [
                    'aid_request_id' => $aid->id,
                    'beneficiary_id' => $aid->beneficiary_id,
                    'beneficiary_name' => trim(($aid->beneficiary->firstname ?? '') . ' ' . ($aid->beneficiary->lastname ?? '')),
                    'fund_type' => $aid->fund_type,
                    'amount' => (float) $aid->amount,
                    'notes' => $request->notes,
                ],
                'aid_request',
                $aid->id,
                $request->status === 'approved' ? 'high' : 'medium',
                'financial'
            );
        } catch (\Throwable $e) {
        }

        return response()->json(['success' => true, 'data' => $aid, 'message' => 'Final decision recorded.']);
    }

    /**
     * Preview COLA calculation for beneficiary before submitting request
     */
    public function previewColaCalculation(Request $request)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json(['success' => false, 'message' => 'Only beneficiaries can preview COLA calculations.'], 403);
        }

        // Get latest approved enrollment verification
        $latest = BeneficiaryDocumentSubmission::where('beneficiary_id', $user->id)
            ->where('status', 'approved')
            ->orderByDesc('created_at')->first();
            
        if (!$latest) {
            return response()->json([
                'success' => false,
                'message' => 'You must have an approved enrollment verification to preview COLA calculations.'
            ], 422);
        }

        // Update user's scholarship status from latest enrollment verification
        if ($user->is_scholar !== $latest->is_scholar) {
            $user->is_scholar = $latest->is_scholar;
            $user->save();
        }

        // Build the 5-month window from enrollment month and select server current month/year by default
        $enrollmentDate = \Carbon\Carbon::parse($latest->enrollment_date);
        $allowed = [];
        for ($i = 0; $i < 5; $i++) {
            $d = $enrollmentDate->copy()->addMonths($i);
            $allowed[] = ['month' => $d->month, 'year' => $d->year];
        }
        // Default to server current period
        $month = (int) now()->month;
        $year = (int) now()->year;
        $isValid = false;
        foreach ($allowed as $a) {
            if ((int)$a['month'] === $month && (int)$a['year'] === $year) { $isValid = true; break; }
        }
        // If provided month/year exist and are valid within window, use them (optional preview)
        if (!$isValid && $request->has('month') && $request->has('year')) {
            $pm = (int) $request->month; $py = (int) $request->year;
            foreach ($allowed as $a) {
                if ((int)$a['month'] === $pm && (int)$a['year'] === $py) { $isValid = true; $month = $pm; $year = $py; break; }
            }
        }
        if (!$isValid) {
            // Not in semester window; return can_request=false
            return response()->json([
                'success' => true,
                'data' => [
                    'base_amount' => 0,
                    'deduction_amount' => 0,
                    'final_amount' => 0,
                    'sunday_absences' => 0,
                    'is_scholar' => $user->is_scholar,
                    'period' => ['month' => $month, 'year' => $year],
                    'enrollment_date' => $latest->enrollment_date,
                    'allowed_months' => $allowed,
                    'attendance_summary' => ['present_days'=>0,'absent_days'=>0,'excused_days'=>0,'total_days'=>0],
                    'can_request' => false,
                ],
                'message' => 'COLA is not available this month. Your semester covers only 5 months starting from your enrollment month.'
            ]);
        }
        
        // Calculate base COLA amount based on scholarship status
        $baseAmount = $user->is_scholar ? 2000 : 1500;
        
        // Get attendance summary
        $attendanceSummary = BeneficiaryAttendance::getMonthlyAttendanceSummary($user->id, $year, $month);
        
        // Calculate deduction based on Sunday absences
        $deduction = BeneficiaryAttendance::calculateColaDeduction($user->id, $year, $month);
        
        // Final COLA amount cannot be negative
        $finalAmount = max(0, $baseAmount - $deduction);
        
        $colaCalculation = [
            'base_amount' => $baseAmount,
            'deduction_amount' => $deduction,
            'final_amount' => $finalAmount,
            'sunday_absences' => $attendanceSummary['sunday_absences'],
            'is_scholar' => $user->is_scholar,
            'period' => ['month' => $month, 'year' => $year],
            'enrollment_date' => $latest->enrollment_date,
            'allowed_months' => $allowed,
            'attendance_summary' => $attendanceSummary,
            'can_request' => $finalAmount > 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $colaCalculation,
            'message' => $finalAmount > 0 ? 'COLA calculation preview generated.' : 'No COLA can be requested for this period due to attendance deductions.'
        ]);
    }
}
