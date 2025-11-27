<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\FinancialAid;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CaseworkerController extends Controller
{
    /**
     * Get all available caseworkers for the current facility
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Check if user has a facility
        $facility = FinancialAid::where('user_id', $user->id)->first();
        if (!$facility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility found. Please register a facility first.'
            ], 404);
        }

        // Only directors can view caseworkers list for assignment purposes
        if (!$user->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Only directors can access caseworker assignments.'
            ], 403);
        }

        // Get all active caseworkers for this facility
        $caseworkers = User::with('systemRole')
            ->whereHas('systemRole', function ($query) {
                $query->where('name', 'caseworker');
            })
            ->where('financial_aid_id', $facility->id)
            ->where('status', 'active')
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get()
            ->map(function ($caseworker) {
                return [
                    'id' => $caseworker->id,
                    'name' => $caseworker->full_name,
                    'email' => $caseworker->email,
                    'contact_number' => $caseworker->contact_number,
                    'beneficiaries_count' => $caseworker->assignedBeneficiaries()->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $caseworkers,
            'facility' => $facility,
            'message' => 'Caseworkers retrieved successfully.'
        ]);
    }

    /**
     * Get caseworkers for dropdown selection (simplified)
     */
    public function forDropdown(Request $request)
    {
        $user = Auth::user();
        
        // Check if user has a facility
        $facility = FinancialAid::where('user_id', $user->id)->first();
        if (!$facility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility found.'
            ], 404);
        }

        // Only directors can access this for beneficiary assignment
        if (!$user->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied.'
            ], 403);
        }

        // Get simplified caseworker list for dropdown
        $caseworkers = User::with('systemRole')
            ->whereHas('systemRole', function ($query) {
                $query->where('name', 'caseworker');
            })
            ->where('financial_aid_id', $facility->id)
            ->where('status', 'active')
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get()
            ->map(function ($caseworker) {
                return [
                    'id' => $caseworker->id,
                    'name' => $caseworker->full_name,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $caseworkers
        ]);
    }

    /**
     * Assign a beneficiary to a caseworker
     */
    public function assignBeneficiary(Request $request)
    {
        $user = Auth::user();
        
        // Only directors can assign beneficiaries to caseworkers
        if (!$user->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Only directors can assign beneficiaries to caseworkers.'
            ], 403);
        }

        $request->validate([
            'beneficiary_id' => ['required', 'integer', 'exists:users,id'],
            'caseworker_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            // Get the facility
            $facility = FinancialAid::where('user_id', $user->id)->first();
            if (!$facility) {
                return response()->json([
                    'success' => false,
                    'message' => 'No facility found.'
                ], 404);
            }

            // Verify beneficiary belongs to this facility
            $beneficiary = User::where('id', $request->beneficiary_id)
                ->where('financial_aid_id', $facility->id)
                ->whereHas('systemRole', function ($query) {
                    $query->where('name', 'beneficiary');
                })
                ->first();

            if (!$beneficiary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Beneficiary not found or does not belong to your facility.'
                ], 404);
            }

            // Verify caseworker belongs to this facility
            $caseworker = User::where('id', $request->caseworker_id)
                ->where('financial_aid_id', $facility->id)
                ->whereHas('systemRole', function ($query) {
                    $query->where('name', 'caseworker');
                })
                ->first();

            if (!$caseworker) {
                return response()->json([
                    'success' => false,
                    'message' => 'Caseworker not found or does not belong to your facility.'
                ], 404);
            }

            // Update the beneficiary's caseworker assignment
            $beneficiary->caseworker_id = $caseworker->id;
            $beneficiary->save();

            // Record to audit log for caseworker visibility
            try {
                AuditLog::logBeneficiaryAssignment($caseworker->id, [
                    'beneficiary_id' => $beneficiary->id,
                    'beneficiary_name' => $beneficiary->full_name,
                    'caseworker_id' => $caseworker->id,
                    'caseworker_name' => $caseworker->full_name,
                    'assigned_by' => $user->full_name,
                    'facility_id' => $facility->id,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Failed to create audit log for beneficiary assignment', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Notify the caseworker in real-time about the new assignment
            try {
                Notification::notifyCaseworkerOfAssignment(
                    $caseworker->id,
                    $beneficiary->full_name,
                    $beneficiary->id
                );
            } catch (\Throwable $e) {
                \Log::warning('Failed to notify caseworker of assignment', [
                    'error' => $e->getMessage(),
                    'caseworker_id' => $caseworker->id,
                    'beneficiary_id' => $beneficiary->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Beneficiary {$beneficiary->full_name} has been assigned to caseworker {$caseworker->full_name}."
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign beneficiary to caseworker.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}