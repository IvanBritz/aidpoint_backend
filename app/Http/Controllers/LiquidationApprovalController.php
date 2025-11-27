<?php

namespace App\Http\Controllers;

use App\Models\Liquidation;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\DirectorNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LiquidationApprovalController extends Controller
{
    /**
     * Get liquidations pending approval for the current user's role
     */
    public function getPendingApprovals(Request $request)
    {
        $user = Auth::user()->load('systemRole');
        $query = Liquidation::with(['beneficiary', 'disbursement', 'receipts', 'caseworkerApprover', 'financeApprover', 'directorApprover']);

        // Get user role from systemRole relationship
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;

        // Filter based on user role and approval level
        switch ($userRole) {
            case 'caseworker':
                $query->where('status', 'pending_caseworker_approval')
                      ->whereHas('beneficiary', function ($q) use ($user) {
                          $q->where('caseworker_id', $user->id);
                      });
                break;
                
            case 'finance':
                $query->where('status', 'pending_finance_approval');
                break;
                
            case 'director':
                $query->where('status', 'pending_director_approval');
                break;
                
            default:
                return response()->json(['message' => 'Unauthorized role for approvals: ' . ($userRole ?: 'unknown')], 403);
        }

        $liquidations = $query->orderBy('created_at', 'asc')->get();

        // Compose approver full names for each liquidation so frontend can display them directly
        $payload = $liquidations->map(function ($l) {
            $arr = $l->toArray();
            $arr['caseworker_name'] = $l->caseworkerApprover ? $l->caseworkerApprover->full_name : null;
            $arr['finance_name'] = $l->financeApprover ? $l->financeApprover->full_name : null;
            return $arr;
        });

        return response()->json([
            'data' => $payload,
            'count' => $payload->count()
        ]);
    }

    /**
     * Get liquidation details for approval review
     */
    public function getLiquidationForApproval($id)
    {
        $user = Auth::user()->load('systemRole');
        $liquidation = Liquidation::with([
            'beneficiary', 
            'disbursement', 
            'receipts',
            'caseworkerApprover',
            'financeApprover',
            'directorApprover'
        ])->findOrFail($id);

        // Check if user has permission to approve this liquidation
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;
        $canApprove = false;
        
        switch ($userRole) {
            case 'caseworker':
                $canApprove = $liquidation->status === 'pending_caseworker_approval' &&
                             $liquidation->beneficiary->caseworker_id === $user->id;
                break;
                
            case 'finance':
                $canApprove = $liquidation->status === 'pending_finance_approval';
                break;
                
            case 'director':
                $canApprove = $liquidation->status === 'pending_director_approval';
                break;
        }

        if (!$canApprove) {
            return response()->json(['message' => 'Unauthorized to approve this liquidation'], 403);
        }

        return response()->json($liquidation);
    }

    /**
     * Submit liquidation for caseworker approval
     */
    public function submitForApproval(Request $request, $id)
    {
        $liquidation = Liquidation::findOrFail($id);
        $user = Auth::user();

        // Only beneficiary can submit their own liquidation
        if ($liquidation->beneficiary_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($liquidation->status !== 'complete') {
            return response()->json(['message' => 'Liquidation must be complete before submission'], 400);
        }

        $liquidation->submitForApproval();
        $liquidation->load(['beneficiary', 'disbursement.aidRequest']);

        // Create audit log for liquidation submission
        try {
            $beneficiaryName = trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? ''));
            $amount = (float) ($liquidation->total_disbursed_amount ?? $liquidation->disbursement?->aidRequest?->amount ?? 0);
            
            AuditLog::logLiquidationSubmitted($liquidation->id, [
                'liquidation_id' => $liquidation->id,
                'beneficiary_id' => $liquidation->beneficiary_id,
                'beneficiary_name' => $beneficiaryName,
                'amount' => $amount,
                'disbursement_type' => $liquidation->disbursement_type,
                'total_receipts' => $liquidation->receipts()->count(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to create audit log for liquidation submission', [
                'error' => $e->getMessage(),
                'liquidation_id' => $liquidation->id,
            ]);
        }

        // Notify assigned caseworker that a liquidation needs their approval
        try {
            $caseworkerId = $liquidation->beneficiary?->caseworker_id;
            if ($caseworkerId) {
                $payload = [
                    'liquidation_id' => $liquidation->id,
                    'amount' => (float) ($liquidation->total_disbursed_amount ?? $liquidation->disbursement?->aidRequest?->amount ?? 0),
                    'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                ];
                Notification::notifyLiquidationPendingApproval([$caseworkerId], $payload, 'high');
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to notify caseworker of liquidation submission', [
                'error' => $e->getMessage(),
                'liquidation_id' => $liquidation->id,
            ]);
        }
        
        return response()->json([
            'message' => 'Liquidation submitted for caseworker approval',
            'liquidation' => $liquidation->fresh()
        ]);
    }

    /**
     * Approve liquidation at caseworker level
     */
    public function approveByCaseworker(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000'
        ]);

        $liquidation = Liquidation::findOrFail($id);
        $user = Auth::user()->load('systemRole');
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;

        if ($userRole !== 'caseworker') {
            return response()->json(['message' => 'Unauthorized - not a caseworker'], 403);
        }

        if ($liquidation->beneficiary->caseworker_id !== $user->id) {
            return response()->json(['message' => 'You can only approve liquidations for your assigned beneficiaries'], 403);
        }

        if ($liquidation->status !== 'pending_caseworker_approval') {
            return response()->json(['message' => 'Liquidation is not in correct status for caseworker approval'], 400);
        }

        $liquidation->approveByCaseworker($user->id, $request->notes);
        $liquidation->load('beneficiary');

        // Create audit log for caseworker approval
        try {
            $beneficiaryName = trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? ''));
            $approverName = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
            
            AuditLog::logEvent(
                'liquidation_caseworker_approved',
                "Caseworker approved liquidation for {$beneficiaryName}",
                [
                    'liquidation_id' => $liquidation->id,
                    'beneficiary_id' => $liquidation->beneficiary_id,
                    'beneficiary_name' => $beneficiaryName,
                    'amount' => (float) ($liquidation->total_disbursed_amount ?? 0),
                    'approver_name' => $approverName,
                    'notes' => $request->notes,
                ],
                'liquidation',
                $liquidation->id,
                'medium',
                'financial'
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to create audit log for caseworker liquidation approval', [
                'error' => $e->getMessage(),
                'liquidation_id' => $liquidation->id,
            ]);
        }

        // Realtime: notify finance officers in this facility
        try {
            $facilityId = $liquidation->beneficiary?->financial_aid_id;
            if ($facilityId) {
                $financeIds = User::whereHas('systemRole', function($q){ $q->where('name','finance'); })
                    ->where('financial_aid_id', $facilityId)->pluck('id')->all();
                if ($financeIds) {
                    $payload = [
                        'liquidation_id' => $liquidation->id,
                        'amount' => (float) ($liquidation->total_disbursed_amount ?? 0),
                        'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                    ];
                    Notification::notifyFinanceLiquidationPending($financeIds, $payload, 'high');
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('notifyFinanceLiquidationPending failed', ['liquidation_id'=>$liquidation->id,'error'=>$e->getMessage()]);
        }
        
        return response()->json([
            'message' => 'Liquidation approved by caseworker and forwarded to finance team',
            'liquidation' => $liquidation->fresh()
        ]);
    }

    /**
     * Reject liquidation at caseworker level
     */
    public function rejectByCaseworker(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000'
        ]);

        $liquidation = Liquidation::findOrFail($id);
        $user = Auth::user()->load('systemRole');
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;

        if ($userRole !== 'caseworker') {
            return response()->json(['message' => 'Unauthorized - not a caseworker'], 403);
        }

        if ($liquidation->beneficiary->caseworker_id !== $user->id) {
            return response()->json(['message' => 'You can only reject liquidations for your assigned beneficiaries'], 403);
        }

        if ($liquidation->status !== 'pending_caseworker_approval') {
            return response()->json(['message' => 'Liquidation is not in correct status for caseworker rejection'], 400);
        }

        $liquidation->rejectAtCaseworkerLevel($user->id, $request->reason);
        
        return response()->json([
            'message' => 'Liquidation rejected by caseworker',
            'liquidation' => $liquidation->fresh()
        ]);
    }

    /**
     * Approve liquidation at finance level
     */
    public function approveByFinance(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000'
        ]);

        $liquidation = Liquidation::findOrFail($id);
        $user = Auth::user()->load('systemRole');
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;

        if ($userRole !== 'finance') {
            return response()->json(['message' => 'Unauthorized - not finance'], 403);
        }

        if ($liquidation->status !== 'pending_finance_approval') {
            return response()->json(['message' => 'Liquidation is not in correct status for finance approval'], 400);
        }

        $liquidation->approveByFinance($user->id, $request->notes);
        
        // Log the finance approval
        try {
            $liquidationData = [
                'liquidation_id' => $liquidation->id,
                'beneficiary_id' => $liquidation->beneficiary_id,
                'amount' => $liquidation->total_disbursed_amount,
                'beneficiary_name' => $liquidation->beneficiary->firstname . ' ' . $liquidation->beneficiary->lastname,
                'approver_name' => $user->firstname . ' ' . $user->lastname,
                'notes' => $request->notes,
            ];
            
            AuditLog::logEvent(
                'liquidation_finance_approved',
                'Liquidation approved by Finance: ₱' . number_format($liquidation->total_disbursed_amount, 2) . ' for ' . $liquidationData['beneficiary_name'],
                $liquidationData,
                'liquidation',
                $liquidation->id,
                'high',
                'financial'
            );
            
            // Notify beneficiary
            Notification::notifyLiquidationStatusChange(
                $liquidation->beneficiary_id,
                'approved_by_finance',
                $liquidationData,
                $liquidationData['approver_name'],
                'medium'
            );
            
            // Notify directors for next step using DirectorNotificationService
            DirectorNotificationService::notifyNewLiquidationApproval($liquidation->fresh('beneficiary'));
        } catch (\Exception $e) {
            \Log::warning('Failed to create audit log or notification for finance approval', [
                'error' => $e->getMessage(),
                'liquidation_id' => $liquidation->id,
            ]);
        }
        
        return response()->json([
            'message' => 'Liquidation approved by finance team and forwarded to project director',
            'liquidation' => $liquidation->fresh()
        ]);
    }

    /**
     * Reject liquidation at finance level
     */
    public function rejectByFinance(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000'
        ]);

        $liquidation = Liquidation::findOrFail($id);
        $user = Auth::user()->load('systemRole');
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;

        if ($userRole !== 'finance') {
            return response()->json(['message' => 'Unauthorized - not finance'], 403);
        }

        if ($liquidation->status !== 'pending_finance_approval') {
            return response()->json(['message' => 'Liquidation is not in correct status for finance rejection'], 400);
        }

        $liquidation->rejectAtFinanceLevel($user->id, $request->reason);
        
        try {
            $liquidation->load('beneficiary');
            $payload = [
                'amount' => (float) ($liquidation->total_disbursed_amount ?? 0),
                'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                'approver_name' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')),
                'reason' => $request->reason,
            ];
            AuditLog::logEvent(
                'liquidation_finance_rejected',
                'Liquidation rejected by Finance for ' . $payload['beneficiary_name'],
                $payload,
                'liquidation',
                $liquidation->id,
                'high',
                'financial'
            );
        } catch (\Throwable $e) { }
        
        return response()->json([
            'message' => 'Liquidation rejected by finance team',
            'liquidation' => $liquidation->fresh()
        ]);
    }

    /**
     * Approve liquidation at director level (final approval)
     */
    public function approveByDirector(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000'
        ]);

        $liquidation = Liquidation::findOrFail($id);
        $user = Auth::user()->load('systemRole');
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;

        if ($userRole !== 'director') {
            return response()->json(['message' => 'Unauthorized - not director'], 403);
        }

        if ($liquidation->status !== 'pending_director_approval') {
            return response()->json(['message' => 'Liquidation is not in correct status for director approval'], 400);
        }

        $liquidation->approveByDirector($user->id, $request->notes);

        try {
            $liquidation->load('beneficiary');
            $payload = [
                'liquidation_id' => $liquidation->id,
                'beneficiary_id' => $liquidation->beneficiary_id,
                'amount' => (float) ($liquidation->total_disbursed_amount ?? 0),
                'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                'approver_name' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')),
                'notes' => $request->notes,
                'director_approved_at' => now()->toDateTimeString(),
            ];
            AuditLog::logEvent(
                'liquidation_director_approved',
                'Liquidation approved by Director for ' . $payload['beneficiary_name'],
                $payload,
                'liquidation',
                $liquidation->id,
                'high',
                'financial'
            );
        } catch (\Throwable $e) { }

        // Notify caseworker that this liquidation is fully completed and is now in their completed list
        try {
            $liquidation->load(['beneficiary', 'disbursement.aidRequest']);
            $caseworkerId = $liquidation->beneficiary?->caseworker_id;
            if ($caseworkerId) {
                $payload = [
                    'liquidation_id' => $liquidation->id,
                    'amount' => (float) ($liquidation->total_disbursed_amount ?? $liquidation->disbursement?->aidRequest?->amount ?? 0),
                    'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                ];
                Notification::notifyCaseworkerLiquidationCompleted($caseworkerId, $payload, 'medium');
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to notify caseworker of completed liquidation', [
                'error' => $e->getMessage(),
                'liquidation_id' => $liquidation->id,
            ]);
        }

        // Notify directors (FYI) that the liquidation is completed
        try {
            \App\Services\DirectorNotificationService::notifyLiquidationCompleted($liquidation);
        } catch (\Throwable $e) {
            \Log::warning('notifyLiquidationCompleted failed', ['liquidation_id' => $liquidation->id, 'error' => $e->getMessage()]);
        }

        // Notify finance officers about completed liquidation
        try {
            $liquidation->load('beneficiary');
            $facilityId = $liquidation->beneficiary?->financial_aid_id;
            if ($facilityId) {
                $financeIds = User::whereHas('systemRole', function($q){ $q->where('name','finance'); })
                    ->where('financial_aid_id', $facilityId)->pluck('id')->all();
                if ($financeIds) {
                    $payload = [
                        'liquidation_id' => $liquidation->id,
                        'amount' => (float) ($liquidation->total_disbursed_amount ?? 0),
                        'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                    ];
                    Notification::notifyFinanceLiquidationCompleted($financeIds, $payload, 'medium');
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('notifyFinanceLiquidationCompleted failed', ['liquidation_id'=>$liquidation->id,'error'=>$e->getMessage()]);
        }
        
        return response()->json([
            'message' => 'Liquidation fully approved by project director',
            'liquidation' => $liquidation->fresh()
        ]);
    }

    /**
     * Reject liquidation at director level
     */
    public function rejectByDirector(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000'
        ]);

        $liquidation = Liquidation::findOrFail($id);
        $user = Auth::user()->load('systemRole');
        $userRole = $user->systemRole ? strtolower($user->systemRole->name) : null;

        if ($userRole !== 'director') {
            return response()->json(['message' => 'Unauthorized - not director'], 403);
        }

        if ($liquidation->status !== 'pending_director_approval') {
            return response()->json(['message' => 'Liquidation is not in correct status for director rejection'], 400);
        }

        $liquidation->rejectAtDirectorLevel($user->id, $request->reason);
        
        try {
            $liquidation->load('beneficiary');
            $payload = [
                'amount' => (float) ($liquidation->total_disbursed_amount ?? 0),
                'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                'approver_name' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')),
                'reason' => $request->reason,
                'director_rejected_at' => now()->toDateTimeString(),
            ];
            AuditLog::logEvent(
                'liquidation_director_rejected',
                'Liquidation rejected by Director for ' . $payload['beneficiary_name'],
                $payload,
                'liquidation',
                $liquidation->id,
                'high',
                'financial'
            );
        } catch (\Throwable $e) { }
        
        return response()->json([
            'message' => 'Liquidation rejected by project director',
            'liquidation' => $liquidation->fresh()
        ]);
    }

    /**
     * Get approval history for a liquidation
     */
    public function getApprovalHistory($id)
    {
        $liquidation = Liquidation::with([
            'caseworkerApprover',
            'financeApprover', 
            'directorApprover'
        ])->findOrFail($id);

        $history = [];

        if ($liquidation->caseworker_approved_at) {
            $history[] = [
                'level' => 'Caseworker',
                'approver' => $liquidation->caseworkerApprover->name,
                'action' => $liquidation->rejected_at_level === 'caseworker' ? 'Rejected' : 'Approved',
                'notes' => $liquidation->caseworker_notes,
                'date' => $liquidation->caseworker_approved_at
            ];
        }

        if ($liquidation->finance_approved_at) {
            $history[] = [
                'level' => 'Finance Team',
                'approver' => $liquidation->financeApprover->name,
                'action' => $liquidation->rejected_at_level === 'finance' ? 'Rejected' : 'Approved',
                'notes' => $liquidation->finance_notes,
                'date' => $liquidation->finance_approved_at
            ];
        }

        if ($liquidation->director_approved_at) {
            $history[] = [
                'level' => 'Project Director',
                'approver' => $liquidation->directorApprover->name,
                'action' => $liquidation->rejected_at_level === 'director' ? 'Rejected' : 'Approved',
                'notes' => $liquidation->director_notes,
                'date' => $liquidation->director_approved_at
            ];
        }

        return response()->json([
            'liquidation' => $liquidation,
            'approval_history' => $history
        ]);
    }
}