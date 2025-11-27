<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\FinancialAid;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class BeneficiaryController extends Controller
{
    /**
     * Get beneficiaries for the current facility
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

        $perPage = $request->get('per_page', 10);
        
        // Get beneficiaries for this specific facility only
        $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
        $beneficiaries = User::with(['caseworker' => function ($query) {
                $query->select('id', 'firstname', 'middlename', 'lastname', 'email');
            }])
            ->where('systemrole_id', $beneficiaryRoleId)
            ->where('financial_aid_id', $facility->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Append whether an exit-letter request has already been sent (hide button on frontend)
        $beneficiaries->getCollection()->transform(function ($b) {
            $b->exit_letter_requested = \App\Models\Notification::where('user_id', $b->id)
                ->where('type', 'exit_letter_request')
                ->exists();
            return $b;
        });

        return response()->json([
            'success' => true,
            'data' => $beneficiaries,
            'facility' => $facility,
            'message' => 'Beneficiaries retrieved successfully.'
        ]);
    }

    /**
     * Store a new beneficiary
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        // Only directors can create beneficiaries
        if (!$user->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Only directors can register beneficiaries.'
            ], 403);
        }
        
        // Check if user has a facility and it's approved
        $facility = FinancialAid::where('user_id', $user->id)->first();
        if (!$facility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility found. Please register a facility first.'
            ], 404);
        }

        if (!$facility->isManagable) {
            return response()->json([
                'success' => false,
                'message' => 'Your facility must be approved before you can register beneficiaries.'
            ], 422);
        }

        $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            // 'password' => ['nullable', 'confirmed', Rules\\Password::defaults()], // optional custom temp password
            'birthdate' => ['required', 'date', 'before:today'],
            'enrolled_school' => ['required', 'string', 'max:200'],
            'school_year' => ['required', 'string', 'max:50'],
            'caseworker_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        // Verify that the selected caseworker is valid for this facility
        $caseworker = User::where('id', $request->caseworker_id)
            ->where('financial_aid_id', $facility->id)
            ->whereHas('systemRole', function ($query) {
                $query->where('name', 'caseworker');
            })
            ->where('status', 'active')
            ->first();

        if (!$caseworker) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid caseworker selection. The caseworker must belong to your facility and be active.'
            ], 422);
        }

        try {
            // Generate a strong temporary password if one isn't provided
            $tempPassword = $request->filled('password')
                ? (string) $request->string('password')
                : Str::password(12);

            $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
            $beneficiary = User::create([
                'firstname' => $request->firstname,
                'middlename' => $request->middlename,
                'lastname' => $request->lastname,
                'contact_number' => $request->contact_number,
                'address' => $request->address,
                'email' => $request->email,
                'password' => Hash::make($tempPassword),
                'must_change_password' => true,
                'status' => 'active',
                'systemrole_id' => $beneficiaryRoleId,
                'financial_aid_id' => $facility->id, // Link to facility
                'caseworker_id' => $request->caseworker_id, // Assign to caseworker
                'birthdate' => $request->birthdate,
                'enrolled_school' => $request->enrolled_school,
                'school_year' => $request->school_year,
            ]);

            // Send email verification link to the new beneficiary
            event(new Registered($beneficiary));
            
            // Notify the caseworker about the new beneficiary assignment
            try {
                $notification = Notification::notifyCaseworkerOfAssignment(
                    $caseworker->id,
                    $beneficiary->firstname . ' ' . $beneficiary->lastname,
                    $beneficiary->id
                );
                \Log::info('Notification created', ['notification_id' => $notification->id, 'caseworker_id' => $caseworker->id]);
            } catch (\Exception $e) {
                \Log::error('Notification failed', ['error' => $e->getMessage()]);
            }

            // Record to audit log for caseworker visibility
            try {
                AuditLog::logBeneficiaryAssignment($caseworker->id, [
                    'beneficiary_id' => $beneficiary->id,
                    'beneficiary_name' => $beneficiary->firstname . ' ' . $beneficiary->lastname,
                    'caseworker_id' => $caseworker->id,
                    'caseworker_name' => $caseworker->full_name,
                    'assigned_by' => $user->full_name,
                    'facility_id' => $facility->id,
                    'is_new_beneficiary' => true,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Failed to create audit log for beneficiary assignment', [
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $beneficiary->load('caseworker'),
                'temporary_password' => $tempPassword,
                'message' => "Beneficiary registered successfully and assigned to caseworker {$caseworker->full_name}. A temporary password was generated."
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register beneficiary.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified beneficiary
     */
    public function show($id)
    {
        $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
        $beneficiary = User::where('systemrole_id', $beneficiaryRoleId)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $beneficiary,
            'message' => 'Beneficiary retrieved successfully.'
        ]);
    }

    /**
     * Update the specified beneficiary
     */
    public function update(Request $request, $id)
    {
        $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
        $beneficiary = User::where('systemrole_id', $beneficiaryRoleId)
            ->findOrFail($id);

        $actor = Auth::user();

        // Permissions: directors can edit any beneficiary in their facility; caseworkers only their assigned beneficiary
        $isDirector = $actor->systemRole && strtolower($actor->systemRole->name) === 'director';
        $isCaseworker = $actor->systemRole && strtolower($actor->systemRole->name) === 'caseworker';

        if ($isCaseworker && $beneficiary->caseworker_id !== $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit beneficiaries assigned to you.'
            ], 403);
        }

        $rules = [
            'firstname' => ['sometimes', 'required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'lastname' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'birthdate' => ['sometimes', 'required', 'date', 'before:today'],
            'enrolled_school' => ['sometimes', 'required', 'string', 'max:200'],
            'school_year' => ['sometimes', 'required', 'string', 'max:50'],
        ];
        // Only directors can change email/status
        if ($isDirector) {
            $rules['email'] = ['sometimes', 'required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$id];
            $rules['status'] = ['sometimes', 'in:active,inactive'];
        }

        $request->validate($rules);

        try {
            $allowed = [
                'firstname', 'middlename', 'lastname', 'contact_number', 'address', 'birthdate', 'enrolled_school', 'school_year'
            ];
            if ($isDirector) {
                $allowed[] = 'email';
                $allowed[] = 'status';
            }

            $beneficiary->update($request->only($allowed));

            return response()->json([
                'success' => true,
                'data' => $beneficiary,
                'message' => 'Beneficiary updated successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update beneficiary.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified beneficiary
     */
    public function destroy($id)
    {
        $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
        $beneficiary = User::where('systemrole_id', $beneficiaryRoleId)
            ->findOrFail($id);

        try {
            $beneficiary->delete();

            return response()->json([
                'success' => true,
                'message' => 'Beneficiary deleted successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete beneficiary.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List beneficiaries assigned to the authenticated caseworker
     */
    public function myAssigned(Request $request)
    {
        $user = Auth::user();

        // Ensure the requester is a caseworker
        if (!$user->systemRole || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json([
                'success' => false,
                'message' => 'Only caseworkers can access their assigned beneficiaries.'
            ], 403);
        }

        $perPage = $request->get('per_page', 10);

        $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
        $beneficiaries = User::where('systemrole_id', $beneficiaryRoleId)
            ->where('caseworker_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $beneficiaries,
            'message' => 'Assigned beneficiaries retrieved successfully.'
        ]);
    }

    /**
     * Reset and regenerate a beneficiary's temporary password
     */
    public function resetPassword($id)
    {
        $actor = Auth::user();
        $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
        $beneficiary = User::where('systemrole_id', $beneficiaryRoleId)->findOrFail($id);

        $isDirector = $actor->systemRole && strtolower($actor->systemRole->name) === 'director';
        $isCaseworker = $actor->systemRole && strtolower($actor->systemRole->name) === 'caseworker';

        if ($isCaseworker && $beneficiary->caseworker_id !== $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only reset passwords for your assigned beneficiaries.'
            ], 403);
        }

        $tempPassword = Str::password(12);
        $beneficiary->forceFill([
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
        ])->save();

        // Optionally could re-send verification if not verified
        try {
            if (method_exists($beneficiary, 'sendEmailVerificationNotification') && !$beneficiary->hasVerifiedEmail()) {
                $beneficiary->sendEmailVerificationNotification();
            }
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'temporary_password' => $tempPassword,
            'message' => 'Temporary password regenerated successfully.'
        ]);
    }

    /**
     * Director: Request beneficiary to upload an exit letter photo via notification
     */
    public function requestExitLetter($id)
    {
        $actor = Auth::user();
        if (!$actor->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Only directors can perform this action.'
            ], 403);
        }

        // Ensure the beneficiary belongs to the director's facility
        $facility = \App\Models\FinancialAid::where('user_id', $actor->id)->first();
        if (!$facility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility found for current director.'
            ], 404);
        }

        $beneficiaryRoleId = \App\Models\SystemRole::where('name', 'beneficiary')->value('id');
        $beneficiary = User::where('systemrole_id', $beneficiaryRoleId)
            ->where('financial_aid_id', $facility->id)
            ->findOrFail($id);

        // If already requested before, do not duplicate and indicate state
        $alreadyRequested = \App\Models\Notification::where('user_id', $beneficiary->id)
            ->where('type', 'exit_letter_request')
            ->exists();
        if ($alreadyRequested) {
            return response()->json([
                'success' => true,
                'already_requested' => true,
                'message' => 'Exit letter request has already been sent to this beneficiary.'
            ]);
        }

        // Create notification (physical exit letter submission)
        $title = 'Exit Letter Submission Required';
        $message = 'Please submit your original (physical) Exit Letter to the center as soon as possible.';
        $data = [
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->firstname . ' ' . $actor->lastname,
            'facility_id' => $facility->id,
            'facility_name' => $facility->center_name ?? null,
            'action' => 'submit_exit_letter_physical',
        ];
        \App\Models\Notification::createForUser(
            $beneficiary->id,
            'exit_letter_request',
            $title,
            $message,
            $data,
            'high',
            'alert'
        );

        return response()->json([
            'success' => true,
            'already_requested' => false,
            'message' => 'Exit letter request sent to beneficiary.'
        ]);
    }
}
