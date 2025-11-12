<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\FinancialAid;
use App\Models\SystemRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * List employees for the current user's facility.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $facility = FinancialAid::where('user_id', $user->id)->first();
        if (!$facility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility found. Please register a facility first.'
            ], 404);
        }

        $perPage = $request->get('per_page', 10);

        // Allowed staff roles in facility scope (restricted to caseworker & finance)
        $allowedRoles = SystemRole::whereIn('name', ['caseworker','finance'])
            ->pluck('id');

        $employees = User::with('systemRole')
            ->whereIn('systemrole_id', $allowedRoles)
            ->where('financial_aid_id', $facility->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $employees,
            'facility' => $facility,
        ]);
    }

    /**
     * Create a new employee under the current facility.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
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
                'message' => 'Your facility must be approved before you can add employees.'
            ], 422);
        }

        $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'role' => ['required', Rule::in(['caseworker','finance'])],
            'password' => ['nullable', 'string', 'min:8'], // optional custom temp password
        ]);

        // Ensure the role exists even if seeders haven't been run yet
        $role = SystemRole::firstOrCreate(['name' => strtolower($request->role)]);

        // Generate a strong temporary password if one isn't provided
        $tempPassword = $request->filled('password')
            ? (string) $request->string('password')
            : \Illuminate\Support\Str::password(12);

        $employee = User::create([
            'firstname' => $request->firstname,
            'middlename' => $request->middlename,
            'lastname' => $request->lastname,
            'contact_number' => $request->contact_number,
            'address' => $request->address,
            'email' => $request->email,
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
            'status' => 'active',
            'systemrole_id' => $role->id,
            'financial_aid_id' => $facility->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $employee->load('systemRole'),
            'temporary_password' => $tempPassword,
            'message' => 'Employee created. A temporary password was generated. They must verify their email and change password on first login.'
        ], 201);
    }

    /**
     * Update employee details/role.
     */
    public function update(Request $request, $id)
    {
        $current = Auth::user();
        $facility = FinancialAid::where('user_id', $current->id)->first();
        if (!$facility) {
            return response()->json(['success' => false, 'message' => 'No facility found'], 404);
        }

        $employee = User::where('financial_aid_id', $facility->id)->findOrFail($id);

        $request->validate([
            'firstname' => ['sometimes', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'lastname' => ['sometimes', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['sometimes', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$employee->id],
            'status' => ['sometimes', Rule::in(['active','inactive'])],
            'role' => ['sometimes', Rule::in(['employee','caseworker','finance','director'])],
        ]);

        if ($request->filled('role')) {
            $role = SystemRole::where('name', $request->role)->firstOrFail();
            $employee->systemrole_id = $role->id;
        }

        $employee->fill($request->only(['firstname','middlename','lastname','contact_number','address','email','status']));
        $employee->save();

        return response()->json([
            'success' => true,
            'data' => $employee->load('systemRole'),
            'message' => 'Employee updated successfully.'
        ]);
    }

    /**
     * Remove an employee from the facility.
     */
    public function destroy($id)
    {
        $current = Auth::user();
        $facility = FinancialAid::where('user_id', $current->id)->first();
        if (!$facility) {
            return response()->json(['success' => false, 'message' => 'No facility found'], 404);
        }

        $employee = User::where('financial_aid_id', $facility->id)->findOrFail($id);
        $employee->delete();

        return response()->json(['success' => true, 'message' => 'Employee deleted successfully.']);
    }

    /**
     * Reset and regenerate an employee's temporary password.
     */
    public function resetPassword($id)
    {
        $current = Auth::user();
        $facility = FinancialAid::where('user_id', $current->id)->first();
        if (!$facility) {
            return response()->json(['success' => false, 'message' => 'No facility found'], 404);
        }

        $employee = User::where('financial_aid_id', $facility->id)->findOrFail($id);

        $tempPassword = \Illuminate\Support\Str::password(12);
        $employee->forceFill([
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
        ])->save();

        return response()->json([
            'success' => true,
            'temporary_password' => $tempPassword,
            'message' => 'Temporary password regenerated successfully.'
        ]);
    }
}
