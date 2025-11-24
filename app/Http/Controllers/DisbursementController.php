<?php

namespace App\Http\Controllers;

use App\Models\AidRequest;
use App\Models\Disbursement;
use App\Models\Notification;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DisbursementController extends Controller
{
    /**
     * Finance: Get approved aid requests ready for disbursement
     */
    public function readyForFinance(Request $request)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'finance') {
            return response()->json(['success' => false, 'message' => 'Only finance staff can view ready disbursements.'], 403);
        }

        $facilityId = $user->financial_aid_id;
        $perPage = $request->get('per_page', 10);

        // Get approved aid requests that haven't been disbursed yet
        $query = AidRequest::with(['beneficiary'])
            ->where('status', 'approved')
            ->where('stage', 'done')
            ->where('director_decision', 'approved')
            ->whereDoesntHave('disbursements') // No disbursement record yet
            ->whereHas('beneficiary', function ($q) use ($facilityId) {
                $q->where('financial_aid_id', $facilityId);
            })
            ->orderByDesc('director_reviewed_at');

        $page = $query->paginate($perPage);
        return response()->json(['success' => true, 'data' => $page]);
    }

    /**
     * Finance: Disburse cash to caseworker
     */
    public function financeDisburse(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'finance') {
            return response()->json(['success' => false, 'message' => 'Only finance staff can disburse funds.'], 403);
        }

        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $aidRequest = AidRequest::with('beneficiary')->findOrFail($id);

        // Verify this aid request belongs to finance staff's facility
        if (!$aidRequest->beneficiary || $aidRequest->beneficiary->financial_aid_id !== $user->financial_aid_id) {
            try {
                AuditLog::logEvent(
                    'disbursement_unauthorized_attempt',
                    'Unauthorized disbursement attempt by finance for another facility',
                    [
                        'aid_request_id' => $aidRequest->id,
                        'attempted_by' => $user->id,
                        'attempted_facility_id' => $user->financial_aid_id,
                        'beneficiary_facility_id' => $aidRequest->beneficiary?->financial_aid_id,
                    ],
                    'disbursement',
                    null,
                    'critical',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This request does not belong to your facility.'], 403);
        }

        // Verify aid request is approved and not already disbursed
        if ($aidRequest->status !== 'approved' || $aidRequest->stage !== 'done' || $aidRequest->director_decision !== 'approved') {
            try {
                AuditLog::logEvent(
                    'disbursement_invalid_status_attempt',
                    'Attempted disbursement before required approvals',
                    [
                        'aid_request_id' => $aidRequest->id,
                        'status' => $aidRequest->status,
                        'stage' => $aidRequest->stage,
                        'director_decision' => $aidRequest->director_decision,
                    ],
                    'disbursement',
                    null,
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This request is not ready for disbursement.'], 422);
        }

        // Check if already disbursed
        if ($aidRequest->disbursements()->exists()) {
            try {
                AuditLog::logEvent(
                    'disbursement_duplicate_attempt',
                    'Duplicate disbursement attempt detected',
                    [
                        'aid_request_id' => $aidRequest->id,
                    ],
                    'disbursement',
                    null,
                    'critical',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This request has already been disbursed.'], 422);
        }

        // Validate there is enough available budget now (no deduction yet; finalized on beneficiary receipt)
        $amount = (float) $request->amount;
        
        // Sum remaining across all allocations of the requested fund type
        $typeTotal = (float) \App\Models\FundAllocation::where('financial_aid_id', $user->financial_aid_id)
            ->where('is_active', true)
            ->where('fund_type', $aidRequest->fund_type)
            ->sum('remaining_amount');
        
        // If still not enough, include general pool
        $generalTotal = 0.0;
        if ($typeTotal < $amount) {
            $generalTotal = (float) \App\Models\FundAllocation::where('financial_aid_id', $user->financial_aid_id)
                ->where('is_active', true)
                ->where('fund_type', 'general')
                ->sum('remaining_amount');
        }
        
        if (($typeTotal + $generalTotal) < $amount) {
            try {
                AuditLog::logEvent(
                    'disbursement_insufficient_funds',
                    'Insufficient funds detected during disbursement attempt',
                    [
                        'aid_request_id' => $aidRequest->id,
                        'requested_amount' => $amount,
                        'available_type_total' => $typeTotal,
                        'available_general_total' => $generalTotal,
                    ],
                    'disbursement',
                    null,
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'Insufficient funds in your facility\'s allocations to disburse this amount.'], 422);
        }

        // Create disbursement record
        $disbursement = Disbursement::create([
            'aid_request_id' => $aidRequest->id,
            'amount' => $amount,
            'notes' => $request->notes,
            'status' => 'finance_disbursed',
            'finance_disbursed_by' => $user->id,
            'finance_disbursed_at' => now(),
        ]);

        // Notify the assigned caseworker (real-time via Reverb)
        if ($aidRequest->beneficiary->caseworker_id) {
            $caseworkerId = $aidRequest->beneficiary->caseworker_id;
            $payload = [
                'disbursement_id' => $disbursement->id,
                'aid_request_id' => $aidRequest->id,
                'amount' => (float) $amount,
                'beneficiary_name' => trim(($aidRequest->beneficiary->firstname ?? '') . ' ' . ($aidRequest->beneficiary->lastname ?? ''))
            ];
            Notification::notifyDisbursementCreated([$caseworkerId], $payload, 'high');
        }

        try {
            AuditLog::logDisbursementCreated($disbursement->id, [
                'disbursement_id' => $disbursement->id,
                'aid_request_id' => $aidRequest->id,
                'beneficiary_id' => $aidRequest->beneficiary_id,
                'beneficiary_name' => trim(($aidRequest->beneficiary->firstname ?? '') . ' ' . ($aidRequest->beneficiary->lastname ?? '')),
                'amount' => (float) $amount,
                'fund_type' => $aidRequest->fund_type,
                'notes' => $request->notes,
                'finance_disbursed_by' => $user->id,
                'finance_disbursed_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) { }

        return response()->json([
            'success' => true, 
            'data' => $disbursement, 
            'message' => 'Cash disbursed to caseworker successfully.'
        ]);
    }

    /**
     * Caseworker: Acknowledge receipt of cash from finance
     */
    public function caseworkerReceive(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can acknowledge receipt.'], 403);
        }

        $disbursement = Disbursement::with('aidRequest.beneficiary')->findOrFail($id);

        // Verify this disbursement is for caseworker's assigned beneficiary
        if (!$disbursement->aidRequest->beneficiary || 
            $disbursement->aidRequest->beneficiary->caseworker_id !== $user->id) {
            try {
                AuditLog::logEvent(
                    'disbursement_unauthorized_caseworker_attempt',
                    'Unauthorized caseworker receipt attempt',
                    [
                        'disbursement_id' => $disbursement->id,
                        'aid_request_id' => $disbursement->aid_request_id,
                        'attempted_by' => $user->id,
                    ],
                    'disbursement',
                    $disbursement->id,
                    'critical',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'You are not assigned to this beneficiary.'], 403);
        }

        // Verify disbursement is in correct status
        if ($disbursement->status !== 'finance_disbursed') {
            try {
                AuditLog::logEvent(
                    'disbursement_invalid_status_attempt',
                    'Caseworker attempted receipt with invalid status',
                    [
                        'disbursement_id' => $disbursement->id,
                        'current_status' => $disbursement->status,
                    ],
                    'disbursement',
                    $disbursement->id,
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This disbursement is not ready for caseworker receipt.'], 422);
        }

        // Update disbursement status
        $disbursement->update([
            'status' => 'caseworker_received',
            'caseworker_received_by' => $user->id,
            'caseworker_received_at' => now(),
        ]);

        try {
            AuditLog::logEvent(
                'disbursement_caseworker_received',
                'Caseworker acknowledged receipt of cash disbursement',
                [
                    'disbursement_id' => $disbursement->id,
                    'aid_request_id' => $disbursement->aid_request_id,
                    'beneficiary_id' => $disbursement->aidRequest->beneficiary_id,
                    'beneficiary_name' => trim(($disbursement->aidRequest->beneficiary->firstname ?? '') . ' ' . ($disbursement->aidRequest->beneficiary->lastname ?? '')),
                    'amount' => (float) $disbursement->amount,
                    'caseworker_received_by' => $user->id,
                    'caseworker_received_at' => now()->toDateTimeString(),
                ],
                'disbursement',
                $disbursement->id,
                'medium',
                'financial'
            );
        } catch (\Throwable $e) { }

        return response()->json([
            'success' => true, 
            'data' => $disbursement, 
            'message' => 'Cash receipt acknowledged successfully.'
        ]);
    }

    /**
     * Caseworker: Disburse cash to beneficiary
     */
    public function caseworkerDisburse(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can disburse to beneficiaries.'], 403);
        }

        $disbursement = Disbursement::with('aidRequest.beneficiary')->findOrFail($id);

        // Verify this disbursement is for caseworker's assigned beneficiary
        if (!$disbursement->aidRequest->beneficiary || 
            $disbursement->aidRequest->beneficiary->caseworker_id !== $user->id) {
            try {
                AuditLog::logEvent(
                    'disbursement_unauthorized_caseworker_attempt',
                    'Unauthorized caseworker disbursement attempt',
                    [
                        'disbursement_id' => $disbursement->id,
                        'aid_request_id' => $disbursement->aid_request_id,
                        'attempted_by' => $user->id,
                    ],
                    'disbursement',
                    $disbursement->id,
                    'critical',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'You are not assigned to this beneficiary.'], 403);
        }

        // Verify disbursement is in correct status (can disburse after receiving or if already received)
        if (!in_array($disbursement->status, ['finance_disbursed', 'caseworker_received'])) {
            try {
                AuditLog::logEvent(
                    'disbursement_invalid_status_attempt',
                    'Caseworker attempted beneficiary disbursement with invalid status',
                    [
                        'disbursement_id' => $disbursement->id,
                        'current_status' => $disbursement->status,
                    ],
                    'disbursement',
                    $disbursement->id,
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This disbursement is not ready for beneficiary disbursement.'], 422);
        }

        // Update disbursement status
        $updateData = [
            'status' => 'caseworker_disbursed',
            'caseworker_disbursed_by' => $user->id,
            'caseworker_disbursed_at' => now(),
        ];

        // If caseworker didn't previously acknowledge receipt, mark that too
        if ($disbursement->status === 'finance_disbursed') {
            $updateData['caseworker_received_by'] = $user->id;
            $updateData['caseworker_received_at'] = now();
        }

        $disbursement->update($updateData);

        try {
            AuditLog::logEvent(
                'disbursement_caseworker_disbursed',
                'Caseworker disbursed cash to beneficiary',
                [
                    'disbursement_id' => $disbursement->id,
                    'aid_request_id' => $disbursement->aid_request_id,
                    'beneficiary_id' => $disbursement->aidRequest->beneficiary_id,
                    'beneficiary_name' => trim(($disbursement->aidRequest->beneficiary->firstname ?? '') . ' ' . ($disbursement->aidRequest->beneficiary->lastname ?? '')),
                    'amount' => (float) $disbursement->amount,
                    'caseworker_disbursed_by' => $user->id,
                    'caseworker_disbursed_at' => now()->toDateTimeString(),
                ],
                'disbursement',
                $disbursement->id,
                'medium',
                'financial'
            );
        } catch (\Throwable $e) { }

        // Notify the beneficiary
        Notification::create([
            'user_id' => $disbursement->aidRequest->beneficiary_id,
            'title' => 'Cash Ready for Collection',
            'message' => "Your cash assistance of ₱" . number_format($disbursement->amount, 2) . 
                        " is ready for collection from your caseworker " . 
                        $user->firstname . " " . $user->lastname . ".",
            'type' => 'disbursement',
            'data' => json_encode(['disbursement_id' => $disbursement->id]),
        ]);

        return response()->json([
            'success' => true, 
            'data' => $disbursement, 
            'message' => 'Cash disbursed to beneficiary successfully.'
        ]);
    }

    /**
     * Beneficiary: Confirm receipt of cash
     */
    public function beneficiaryReceive(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json(['success' => false, 'message' => 'Only beneficiaries can confirm receipt.'], 403);
        }

        $disbursement = Disbursement::with('aidRequest.beneficiary')->findOrFail($id);

        // Verify this disbursement is for this beneficiary
        if ($disbursement->aidRequest->beneficiary_id !== $user->id) {
            try {
                AuditLog::logEvent(
                    'disbursement_unauthorized_beneficiary_attempt',
                    'Unauthorized beneficiary receipt attempt',
                    [
                        'disbursement_id' => $disbursement->id,
                        'aid_request_id' => $disbursement->aid_request_id,
                        'attempted_by' => $user->id,
                    ],
                    'disbursement',
                    $disbursement->id,
                    'critical',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This disbursement does not belong to you.'], 403);
        }

        // Verify disbursement is in correct status
        if ($disbursement->status !== 'caseworker_disbursed') {
            try {
                AuditLog::logEvent(
                    'disbursement_invalid_status_attempt',
                    'Beneficiary attempted receipt with invalid status',
                    [
                        'disbursement_id' => $disbursement->id,
                        'current_status' => $disbursement->status,
                    ],
                    'disbursement',
                    $disbursement->id,
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json(['success' => false, 'message' => 'This disbursement is not ready for beneficiary receipt.'], 422);
        }

        // Deduct funds from the appropriate allocation now that beneficiary has received
        $amount = (float) $disbursement->amount;
        $fundType = $disbursement->aidRequest->fund_type;
        
        // Get finance user to determine facility
        $financeUser = \App\Models\User::find($disbursement->finance_disbursed_by);
        if (!$financeUser || !$financeUser->financial_aid_id) {
            return response()->json(['success' => false, 'message' => 'Finance user or facility not found.'], 422);
        }

        // Compute total available across all allocations for this fund type; include general funds if needed
        $typeAllocations = \App\Models\FundAllocation::where('financial_aid_id', $financeUser->financial_aid_id)
            ->where('is_active', true)
            ->where('fund_type', $fundType)
            ->orderByDesc('remaining_amount')
            ->get();
        $typeTotal = (float) $typeAllocations->sum('remaining_amount');
        $generalAllocations = collect();
        if ($typeTotal < $amount) {
            $generalAllocations = \App\Models\FundAllocation::where('financial_aid_id', $financeUser->financial_aid_id)
                ->where('is_active', true)
                ->where('fund_type', 'general')
                ->orderByDesc('remaining_amount')
                ->get();
        }
        $grandTotal = $typeTotal + (float) $generalAllocations->sum('remaining_amount');
        if ($grandTotal < $amount) {
            return response()->json(['success' => false, 'message' => 'Insufficient funds to complete this disbursement at this time.'], 422);
        }

        DB::transaction(function () use ($typeAllocations, $generalAllocations, $amount, $disbursement) {
            $remaining = $amount;
            // Deduct from specific fund type allocations first
            foreach ($typeAllocations as $alloc) {
                if ($remaining <= 0) break;
                $take = min((float) $alloc->remaining_amount, $remaining);
                if ($take > 0) {
                    $alloc->utilized_amount = $alloc->utilized_amount + $take;
                    $alloc->updateRemainingAmount();
                    $remaining -= $take;
                }
            }
            // Then use general pool if needed
            if ($remaining > 0) {
                foreach ($generalAllocations as $alloc) {
                    if ($remaining <= 0) break;
                    $take = min((float) $alloc->remaining_amount, $remaining);
                    if ($take > 0) {
                        $alloc->utilized_amount = $alloc->utilized_amount + $take;
                        $alloc->updateRemainingAmount();
                        $remaining -= $take;
                    }
                }
            }

            // Update disbursement status and initialize liquidation tracking fields
            $disbursement->update([
                'status' => 'beneficiary_received',
                'beneficiary_received_at' => now(),
                // Initialize liquidation tracking so the disbursement shows up in the beneficiary's liquidation list
                'liquidated_amount' => 0,
                'remaining_to_liquidate' => $amount,
                'fully_liquidated' => false,
            ]);
        });

        try {
            AuditLog::logEvent(
                'disbursement_beneficiary_received',
                'Beneficiary confirmed receipt of cash disbursement',
                [
                    'disbursement_id' => $disbursement->id,
                    'aid_request_id' => $disbursement->aid_request_id,
                    'beneficiary_id' => $disbursement->aidRequest->beneficiary_id,
                    'beneficiary_name' => trim(($disbursement->aidRequest->beneficiary->firstname ?? '') . ' ' . ($disbursement->aidRequest->beneficiary->lastname ?? '')),
                    'amount' => (float) $disbursement->amount,
                    'beneficiary_received_at' => now()->toDateTimeString(),
                ],
                'disbursement',
                $disbursement->id,
                'high',
                'financial'
            );
        } catch (\Throwable $e) { }

        // Notify finance that the disbursement is complete (real-time)
        Notification::createForUser(
            (int) $disbursement->finance_disbursed_by,
            'beneficiary_cash_received',
            'Disbursement Completed',
            'Beneficiary ' . ($user->firstname . ' ' . $user->lastname) . ' has confirmed receipt of ₱' . number_format($disbursement->amount, 2) . '.',
            [
                'disbursement_id' => $disbursement->id,
                'aid_request_id' => $disbursement->aid_request_id,
                'amount' => (float) $disbursement->amount,
            ],
            'high',
            'financial'
        );

        // Notify caseworker as well
        $caseworkerId = $disbursement->aidRequest->beneficiary->caseworker_id ?? null;
        if ($caseworkerId) {
            Notification::createForUser(
                (int) $caseworkerId,
                'disbursement_beneficiary_received',
                'Beneficiary Received Cash',
                'Your beneficiary has confirmed receipt of ₱' . number_format($disbursement->amount, 2) . '.',
                [
                    'disbursement_id' => $disbursement->id,
                    'aid_request_id' => $disbursement->aid_request_id,
                    'amount' => (float) $disbursement->amount,
                ],
                'medium',
                'financial'
            );
        }

        // After successful beneficiary receipt, check if COLA has been fully utilized for the semester (5 months)
        try {
            $aid = $disbursement->aidRequest()->with('beneficiary')->first();
            if ($aid && $aid->fund_type === 'cola' && $aid->beneficiary) {
                $beneficiaryId = (int) $aid->beneficiary_id;
                // Determine enrollment window
                $latestEnroll = \App\Models\BeneficiaryDocumentSubmission::where('beneficiary_id', $beneficiaryId)
                    ->where('status', 'approved')
                    ->orderByDesc('created_at')->first();
                if ($latestEnroll) {
                    $enroll = \Carbon\Carbon::parse($latestEnroll->enrollment_date);
                    $windowMonths = [];
                    for ($i=0; $i<5; $i++) { $d=$enroll->copy()->addMonths($i); $windowMonths[]=['m'=>$d->month,'y'=>$d->year]; }
                    // Count disbursements received for COLA in the window
                    $count = \App\Models\Disbursement::whereHas('aidRequest', function($q) use ($beneficiaryId, $windowMonths) {
                            $q->where('beneficiary_id', $beneficiaryId)
                              ->where('fund_type','cola')
                              ->where(function($q2) use ($windowMonths){
                                  foreach ($windowMonths as $w) {
                                      $q2->orWhere(function($qq) use ($w){ $qq->where('month',$w['m'])->where('year',$w['y']); });
                                  }
                              });
                        })
                        ->where('status','beneficiary_received')
                        ->count();
                    if ($count >= 5) {
                        // Avoid duplicate notification of utilization
                        $existing = \App\Models\Notification::where('user_id',$beneficiaryId)
                            ->where('type','cola_semester_utilized')->latest()->first();
                        if (!$existing) {
                            Notification::createForUser(
                                $beneficiaryId,
                                'cola_semester_utilized',
                                'COLA Utilized',
                                'Your COLA for this semester has been fully utilized.',
                                [ 'semester_start' => $enroll->format('Y-m-01') ],
                                'normal',
                                'financial'
                            );
                        }
                    }
                }
            }
        } catch (\Throwable $e) { \Log::warning('COLA utilized check failed', ['e'=>$e->getMessage()]); }

        return response()->json([
            'success' => true, 
            'data' => $disbursement, 
            'message' => 'Cash receipt confirmed successfully.'
        ]);
    }

    /**
     * Role-based disbursement list
     */
    public function myDisbursements(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $userRole = strtolower($user->systemRole->name);
        $query = Disbursement::with(['aidRequest.beneficiary']);

        switch ($userRole) {
            case 'finance':
                // Finance sees disbursements for their facility
                $query->forFinance($user->financial_aid_id);
                break;
            case 'caseworker':
                // Caseworker sees disbursements for their assigned beneficiaries
                $query->forCaseworker($user->id);
                break;
            case 'beneficiary':
                // Beneficiary sees their own disbursements
                $query->forBeneficiary($user->id);
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Unauthorized role for viewing disbursements.'], 403);
        }

        $disbursements = $query->orderByDesc('created_at')->get();
        
        return response()->json(['success' => true, 'data' => $disbursements]);
    }

    /**
     * Caseworker: Get disbursements with pagination
     */
    public function forCaseworker(Request $request)
    {
        $user = Auth::user();
        if (!$user || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json(['success' => false, 'message' => 'Only caseworkers can access this endpoint.'], 403);
        }

        $perPage = $request->get('per_page', 10);
        $query = Disbursement::with(['aidRequest.beneficiary'])
            ->forCaseworker($user->id)
            ->orderByDesc('created_at');

        // Apply status filter if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Apply search filter if provided
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->whereHas('aidRequest.beneficiary', function ($q) use ($searchTerm) {
                $q->where('firstname', 'like', "%{$searchTerm}%")
                  ->orWhere('lastname', 'like', "%{$searchTerm}%")
                  ->orWhere('email', 'like', "%{$searchTerm}%");
            });
        }

        $page = $query->paginate($perPage);
        return response()->json(['success' => true, 'data' => $page]);
    }

    /**
     * List disbursements that have been received by beneficiaries
     * - Finance: limited to their facility
     * - Caseworker: limited to assigned beneficiaries
     */
    public function receivedByBeneficiaries(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $perPage = (int) $request->get('per_page', 10);
        // Include beneficiary and their assigned caseworker so the frontend can show the caseworker name
        $query = Disbursement::with([
                'aidRequest.beneficiary.caseworker',
            ])
            ->where('status', 'beneficiary_received')
            ->orderByDesc('beneficiary_received_at');

        $role = strtolower($user->systemRole->name);
        if ($role === 'finance') {
            $query->forFinance($user->financial_aid_id);
        } elseif ($role === 'caseworker') {
            $query->forCaseworker($user->id);
        } else {
            return response()->json(['success' => false, 'message' => 'Unauthorized role for viewing disbursements.'], 403);
        }

        $page = $query->paginate($perPage);
        return response()->json(['success' => true, 'data' => $page]);
    }
}
