<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryDocumentSubmission;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BeneficiaryDocumentSubmissionController extends Controller
{
    /**
     * Get the current beneficiary's latest document submission (if any)
     */
    public function mySubmission(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json([
                'success' => false,
                'message' => 'Only beneficiaries can access their submission.'
            ], 403);
        }

        $submission = BeneficiaryDocumentSubmission::where('beneficiary_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $submission
        ]);
    }

    /**
     * Store or update a beneficiary document submission
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json([
                'success' => false,
                'message' => 'Only beneficiaries can submit documents.'
            ], 403);
        }

        $request->validate([
            'enrollment_date' => ['required', 'date'],
            'year_level' => ['required', 'string', 'max:50'],
            'is_scholar' => ['required', 'boolean'],
            // Images only for Enrollment Certification, Scholarship Certification, and SOA
'enrollment_certification' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'scholarship_certification' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'sao_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        // Find pending submission for update, otherwise create new
        $submission = BeneficiaryDocumentSubmission::where('beneficiary_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (!$submission) {
            $submission = new BeneficiaryDocumentSubmission();
            $submission->beneficiary_id = $user->id;
            $submission->status = 'pending';
        }

        $submission->enrollment_date = $request->enrollment_date;
        $submission->year_level = $request->year_level;
        $submission->is_scholar = $request->boolean('is_scholar');

        // Handle uploads
        $baseDir = 'beneficiary-documents/' . $user->id;
        if ($request->hasFile('enrollment_certification')) {
            // delete old if exists
            if ($submission->enrollment_certification_path) {
                Storage::disk('public')->delete($submission->enrollment_certification_path);
            }
            $path = $request->file('enrollment_certification')->store($baseDir, 'public');
            $submission->enrollment_certification_path = $path;
        }

        // Scholarship certification (required if is_scholar)
        if ($request->boolean('is_scholar')) {
            if ($request->hasFile('scholarship_certification')) {
                if ($submission->scholarship_certification_path) {
                    Storage::disk('public')->delete($submission->scholarship_certification_path);
                }
                $path = $request->file('scholarship_certification')->store($baseDir, 'public');
                $submission->scholarship_certification_path = $path;
            }
            // If still no file on record, validation error
            if (!$request->hasFile('scholarship_certification') && !$submission->scholarship_certification_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Scholarship certification image is required for scholars.'
                ], 422);
            }
        } else {
            // Not a scholar: remove previous file if exists
            if ($submission->scholarship_certification_path) {
                Storage::disk('public')->delete($submission->scholarship_certification_path);
            }
            $submission->scholarship_certification_path = null;
        }

        if ($request->hasFile('sao_photo')) {
            if ($submission->sao_photo_path) {
                Storage::disk('public')->delete($submission->sao_photo_path);
            }
            $path = $request->file('sao_photo')->store($baseDir, 'public');
            $submission->sao_photo_path = $path;
        }

        $submission->status = 'pending'; // reset to pending on (re)submit
        $submission->reviewed_by = null;
        $submission->reviewed_at = null;
        $submission->review_notes = null;
        $submission->save();

        // Notify the assigned caseworker
        if ($user->caseworker_id) {
            Notification::notifyCaseworkerOfSubmission(
                $user->caseworker_id,
                $user->firstname . ' ' . $user->lastname,
                'enrollment'
            );
        }

        return response()->json([
            'success' => true,
            'data' => $submission,
            'message' => 'Documents submitted successfully. Awaiting caseworker review.'
        ], 201);
    }

    /**
     * List pending submissions for the authenticated caseworker
     */
    public function pendingForCaseworker(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json([
                'success' => false,
                'message' => 'Only caseworkers can view pending submissions.'
            ], 403);
        }

        $perPage = $request->get('per_page', 10);

        $query = BeneficiaryDocumentSubmission::with(['beneficiary'])
            ->where('status', 'pending')
            ->whereHas('beneficiary', function ($q) use ($user) {
                $q->where('caseworker_id', $user->id);
            })
            ->orderByDesc('created_at');

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    /**
     * List approved submissions for the authenticated caseworker
     */
    public function approvedByCaseworker(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json([
                'success' => false,
                'message' => 'Only caseworkers can view approved submissions.'
            ], 403);
        }

        $perPage = $request->get('per_page', 10);

        $query = BeneficiaryDocumentSubmission::with(['beneficiary', 'reviewer'])
            ->where('status', 'approved')
            ->where('reviewed_by', $user->id)
            ->orderByDesc('reviewed_at');

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    /**
     * Review a submission (approve or reject) by caseworker
     */
    public function review(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json([
                'success' => false,
                'message' => 'Only caseworkers can review submissions.'
            ], 403);
        }

        $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $submission = BeneficiaryDocumentSubmission::with('beneficiary')->findOrFail($id);

        // Ensure this caseworker is assigned to the beneficiary
        if (!$submission->beneficiary || $submission->beneficiary->caseworker_id !== $user->id) {
            try {
                AuditLog::logEvent(
                    'enrollment_caseworker_unauthorized_attempt',
                    'Unauthorized caseworker enrollment review attempt',
                    [
                        'submission_id' => $submission->id,
                        'attempted_by' => $user->id,
                        'beneficiary_id' => $submission->beneficiary?->id,
                    ],
                    'enrollment',
                    $submission->id,
                    'critical',
                    'user_management'
                );
            } catch (\Throwable $e) { }
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to review this submission.'
            ], 403);
        }

        if ($submission->status !== 'pending') {
            try {
                AuditLog::logEvent(
                    'enrollment_invalid_status_attempt',
                    'Caseworker attempted enrollment review with invalid status',
                    [
                        'submission_id' => $submission->id,
                        'current_status' => $submission->status,
                    ],
                    'enrollment',
                    $submission->id,
                    'high',
                    'user_management'
                );
            } catch (\Throwable $e) { }
            return response()->json([
                'success' => false,
                'message' => 'Only pending submissions can be reviewed.'
            ], 422);
        }

        $submission->status = $request->status;
        $submission->review_notes = $request->review_notes;
        $submission->reviewed_by = $user->id;
        $submission->reviewed_at = now();
        $submission->save();

        // If approved, sync beneficiary fields from this submission
        if ($submission->status === 'approved' && $submission->beneficiary) {
            try {
                $changed = false;
                if ((bool)$submission->beneficiary->is_scholar !== (bool)$submission->is_scholar) {
                    $submission->beneficiary->is_scholar = (bool)$submission->is_scholar;
                    $changed = true;
                }
                // Update displayed school year from approved year level
                $newSchoolYear = (string) ($submission->year_level ?? '');
                if ($newSchoolYear !== '' && $submission->beneficiary->school_year !== $newSchoolYear) {
                    $submission->beneficiary->school_year = $newSchoolYear;
                    $changed = true;
                }
                if ($changed) {
                    $submission->beneficiary->save();
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to sync beneficiary fields from submission', [
                    'beneficiary_id' => $submission->beneficiary_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify the beneficiary of the review result
        Notification::notifyBeneficiaryOfReview(
            $submission->beneficiary_id,
            'enrollment',
            $request->status,
            $user->firstname . ' ' . $user->lastname,
            $request->review_notes
        );

        try {
            $reviewData = [
                'status' => $request->status === 'approved' ? 'approved' : 'rejected',
                'type' => 'enrollment',
                'beneficiary_name' => trim(($submission->beneficiary->firstname ?? '') . ' ' . ($submission->beneficiary->lastname ?? '')),
                'item_id' => $submission->id,
                'review_notes' => $request->review_notes,
            ];
            AuditLog::logCaseworkerReview($reviewData);
        } catch (\Throwable $e) { }

        return response()->json([
            'success' => true,
            'data' => $submission,
            'message' => 'Submission has been ' . $submission->status . '.'
        ]);
    }
}
