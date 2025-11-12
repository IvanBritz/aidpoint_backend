<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundAllocation;
use App\Models\User;
use App\Models\FinancialAid;
use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Notifications\CombinedFundReportNotification;

class FundAllocationController extends Controller
{
    /**
     * Display fund allocations for the current user's facility
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        
        // Only finance officers may manage/view the fund management list
        $role = strtolower($user->systemRole->name ?? '');
        if ($role !== 'finance') {
            return response()->json([
                'success' => false,
                'message' => 'Only finance officers can access fund management.'
            ], 403);
        }

        if (!$user->financial_aid_id) {
            return response()->json([
                'success' => false,
                'message' => 'User is not assigned to a facility.'
            ], 400);
        }
        
        $facilityId = $user->financial_aid_id;

        $allocations = FundAllocation::where('financial_aid_id', $facilityId)
            ->where('is_active', true)
            ->orderBy('fund_type')
            ->orderBy('sponsor_name')
            ->get();
            
        return response()->json([
            'success' => true,
            'data' => $allocations,
            'message' => 'Fund allocations retrieved successfully.'
        ]);
    }

    /**
     * Store a newly created fund allocation
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // Only finance officers may create fund allocations
        $role = strtolower($user->systemRole->name ?? '');
        if ($role !== 'finance') {
            return response()->json([
                'success' => false,
                'message' => 'Only finance officers can create fund allocations.'
            ], 403);
        }

        if (!$user->financial_aid_id) {
            return response()->json([
                'success' => false,
                'message' => 'User is not assigned to a facility.'
            ], 400);
        }
        
        $request->validate([
            'fund_type' => ['required', Rule::in(['tuition', 'cola', 'other'])],
            'sponsor_name' => 'required|string|max:255',
            'allocated_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:1000',
        ]);
        
        // Allow multiple allocations with the same sponsor and/or fund type for the same facility.
        // Previously we blocked duplicates — business rule updated to permit them.
        
        $allocation = FundAllocation::create([
            'financial_aid_id' => $user->financial_aid_id,
            'fund_type' => $request->fund_type,
            'sponsor_name' => $request->sponsor_name,
            'allocated_amount' => $request->allocated_amount,
            'remaining_amount' => $request->allocated_amount,
            'utilized_amount' => 0,
            'description' => $request->description,
            'is_active' => true,
        ]);
        
        // Log the fund creation
        try {
            AuditLog::logFundCreated($allocation->id, [
                'sponsor_name' => $allocation->sponsor_name,
                'allocated_amount' => $allocation->allocated_amount,
                'fund_type' => $allocation->fund_type,
                'description' => $allocation->description,
            ]);
            
            // Notify all finance team members in this facility
            $financeUsers = User::whereHas('systemRole', function ($q) {
                $q->where('name', 'finance');
            })->where('financial_aid_id', $user->financial_aid_id)
              ->where('id', '!=', $user->id)
              ->pluck('id')->toArray();
            
            if (!empty($financeUsers)) {
                foreach ($financeUsers as $financeUserId) {
                    Notification::createForUser(
                        $financeUserId,
                        'new_sponsor',
                        'New Fund Allocated',
                        'A colleague added a new fund: ' . $allocation->sponsor_name . ' (₱' . number_format((float) $allocation->allocated_amount, 2) . ') for ' . strtoupper($allocation->fund_type) . '.',
                        [
                            'sponsor_name' => $allocation->sponsor_name,
                            'allocated_amount' => (float) $allocation->allocated_amount,
                            'fund_type' => $allocation->fund_type,
                            'action_link' => url('/dashboard')
                        ],
                        'medium',
                        'financial'
                    );
                }
            }

            // Also notify the facility director about the new sponsor/allocation
            $facility = FinancialAid::find($user->financial_aid_id);
            $directorId = $facility?->user_id;
            if ($directorId && $directorId != $user->id) {
                Notification::createForUser(
                    (int) $directorId,
                    'new_sponsor',
                    'New Sponsor Added',
                    'A new sponsor ('. $allocation->sponsor_name .') allocated ₱' . number_format((float) $allocation->allocated_amount, 2) . ' to '. strtoupper($allocation->fund_type) .'.',
                    [
                        'sponsor_name' => $allocation->sponsor_name,
                        'allocated_amount' => (float) $allocation->allocated_amount,
                        'fund_type' => $allocation->fund_type,
                        'action_link' => url('/dashboard')
                    ],
                    'high',
                    'financial'
                );
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to create audit log or notification for fund creation', [
                'error' => $e->getMessage(),
                'fund_id' => $allocation->id,
            ]);
        }
        
        return response()->json([
            'success' => true,
            'data' => $allocation,
            'message' => 'Fund allocation created successfully.'
        ], 201);
    }

    /**
     * Display the specified fund allocation
     */
    public function show(string $id): JsonResponse
    {
        $user = Auth::user();
        
        // Only finance officers may view individual allocation details via this endpoint
        $role = strtolower($user->systemRole->name ?? '');
        if ($role !== 'finance') {
            return response()->json([
                'success' => false,
                'message' => 'Only finance officers can access this resource.'
            ], 403);
        }

        if (!$user->financial_aid_id) {
            return response()->json([
                'success' => false,
                'message' => 'User is not assigned to a facility.'
            ], 400);
        }
        
        $allocation = FundAllocation::where('id', $id)
            ->where('financial_aid_id', $user->financial_aid_id)
            ->first();
            
        if (!$allocation) {
            return response()->json([
                'success' => false,
                'message' => 'Fund allocation not found.'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $allocation,
            'message' => 'Fund allocation retrieved successfully.'
        ]);
    }

    /**
     * Update the specified fund allocation
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = Auth::user();
        
        // Only finance officers may update fund allocations
        $role = strtolower($user->systemRole->name ?? '');
        if ($role !== 'finance') {
            return response()->json([
                'success' => false,
                'message' => 'Only finance officers can update fund allocations.'
            ], 403);
        }

        if (!$user->financial_aid_id) {
            return response()->json([
                'success' => false,
                'message' => 'User is not assigned to a facility.'
            ], 400);
        }
        
        $allocation = FundAllocation::where('id', $id)
            ->where('financial_aid_id', $user->financial_aid_id)
            ->first();
            
        if (!$allocation) {
            return response()->json([
                'success' => false,
                'message' => 'Fund allocation not found.'
            ], 404);
        }
        
        $request->validate([
            'fund_type' => ['sometimes', Rule::in(['tuition', 'cola', 'other'])],
            'sponsor_name' => 'sometimes|string|max:255',
            'allocated_amount' => 'sometimes|numeric|min:0',
            'utilized_amount' => 'sometimes|numeric|min:0',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);
        
        // Duplicate sponsor/type combinations are now allowed by business rule; no duplicate check.
        
        // Store original data for audit log
        $originalData = $allocation->toArray();
        
        $allocation->update($request->only([
            'fund_type', 'sponsor_name', 'allocated_amount', 'utilized_amount', 'description', 'is_active'
        ]));
        
        // Update remaining amount
        $allocation->updateRemainingAmount();
        
        // Log the fund update
        try {
            $changes = [];
            $updatedAllocation = $allocation->fresh();
            
            foreach (['fund_type', 'sponsor_name', 'allocated_amount', 'utilized_amount', 'description', 'is_active'] as $field) {
                if ($request->has($field) && $originalData[$field] != $updatedAllocation->$field) {
                    $changes['original_' . $field] = $originalData[$field];
                    $changes['new_' . $field] = $updatedAllocation->$field;
                }
            }
            
            if (!empty($changes)) {
                $changes['sponsor_name'] = $updatedAllocation->sponsor_name;
                $changes['allocated_amount'] = $updatedAllocation->allocated_amount;
                
                AuditLog::logFundUpdated($allocation->id, $changes);
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to create audit log for fund update', [
                'error' => $e->getMessage(),
                'fund_id' => $allocation->id,
            ]);
        }
        
        return response()->json([
            'success' => true,
            'data' => $allocation->fresh(),
            'message' => 'Fund allocation updated successfully.'
        ]);
    }

    /**
     * Remove the specified fund allocation
     */
    public function destroy(string $id): JsonResponse
    {
        $user = Auth::user();
        
        // Only finance officers may delete fund allocations
        $role = strtolower($user->systemRole->name ?? '');
        if ($role !== 'finance') {
            return response()->json([
                'success' => false,
                'message' => 'Only finance officers can delete fund allocations.'
            ], 403);
        }

        if (!$user->financial_aid_id) {
            return response()->json([
                'success' => false,
                'message' => 'User is not assigned to a facility.'
            ], 400);
        }
        
        $allocation = FundAllocation::where('id', $id)
            ->where('financial_aid_id', $user->financial_aid_id)
            ->first();
            
        if (!$allocation) {
            return response()->json([
                'success' => false,
                'message' => 'Fund allocation not found.'
            ], 404);
        }
        
        $allocation->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Fund allocation deleted successfully.'
        ]);
    }
    
    /**
     * Get dashboard summary for finance staff (facility-wide)
     */
    public function dashboard(): JsonResponse
    {
        $user = Auth::user();
        
        // Finance-specific dashboard
        $role = strtolower($user->systemRole->name ?? '');
        if ($role !== 'finance') {
            return response()->json([
                'success' => false,
                'message' => 'Only finance officers can access this dashboard.'
            ], 403);
        }

        if (!$user->financial_aid_id) {
            return response()->json([
                'success' => false,
                'message' => 'User is not assigned to a facility.'
            ], 400);
        }
        
        $allocations = FundAllocation::where('financial_aid_id', $user->financial_aid_id)
            ->where('is_active', true)
            ->get();

        // Exclude deprecated 'general' fund type from summaries
        $allowedTypes = collect(['tuition', 'cola', 'other']);
        $filtered = $allocations->filter(function ($a) use ($allowedTypes) {
            return $allowedTypes->contains(strtolower($a->fund_type));
        });
            
        // Facility info for finance user (center name and director)
        $facility = $user->financialAid()->with('owner')->first();

        $summary = [
            'total_allocated' => $filtered->sum('allocated_amount'),
            'total_utilized' => $filtered->sum('utilized_amount'),
            'total_remaining' => $filtered->sum('remaining_amount'),
            'fund_types' => [
                'tuition' => [
                    'allocated' => $filtered->where('fund_type', 'tuition')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'tuition')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'tuition')->sum('remaining_amount'),
                ],
                'cola' => [
                    'allocated' => $filtered->where('fund_type', 'cola')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'cola')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'cola')->sum('remaining_amount'),
                ],
                'other' => [
                    'allocated' => $filtered->where('fund_type', 'other')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'other')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'other')->sum('remaining_amount'),
                ],
            ],
            'allocations' => $filtered->values(),
            'facility' => $facility ? [
                'id' => $facility->id,
                'center_id' => $facility->center_id,
                'center_name' => $facility->center_name,
                'director' => $facility->owner ? [
                    'id' => $facility->owner->id,
                    'firstname' => $facility->owner->firstname,
                    'middlename' => $facility->owner->middlename,
                    'lastname' => $facility->owner->lastname,
                    'email' => $facility->owner->email,
                ] : null,
            ] : null,
        ];
        
        return response()->json([
            'success' => true,
            'data' => $summary,
            'message' => 'Fund dashboard data retrieved successfully.'
        ]);
    }

    /**
     * Facility-wide dashboard summary for directors.
     * Aggregates allocations for the director's facility.
     */
    public function facilityDashboard(): JsonResponse
    {
        $user = Auth::user();

        // Directors only
        $role = strtolower($user->systemRole->name ?? '');
        if ($role !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Only directors can access the facility fund dashboard.'
            ], 403);
        }

        // Find the facility owned by the director
        $facility = FinancialAid::with('owner')->where('user_id', $user->id)->first();
        if (!$facility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility found for the current user.'
            ], 404);
        }

        // All active allocations for this facility
        $allocations = FundAllocation::where('is_active', true)
            ->where('financial_aid_id', $facility->id)
            ->get();

        // Exclude deprecated 'general' fund type from summaries
        $allowedTypes = collect(['tuition', 'cola', 'other']);
        $filtered = $allocations->filter(function ($a) use ($allowedTypes) {
            return $allowedTypes->contains(strtolower($a->fund_type));
        });

        $summary = [
            'total_allocated' => $filtered->sum('allocated_amount'),
            'total_utilized' => $filtered->sum('utilized_amount'),
            'total_remaining' => $filtered->sum('remaining_amount'),
            'fund_types' => [
                'tuition' => [
                    'allocated' => $filtered->where('fund_type', 'tuition')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'tuition')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'tuition')->sum('remaining_amount'),
                ],
                'cola' => [
                    'allocated' => $filtered->where('fund_type', 'cola')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'cola')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'cola')->sum('remaining_amount'),
                ],
                'other' => [
                    'allocated' => $filtered->where('fund_type', 'other')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'other')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'other')->sum('remaining_amount'),
                ],
            ],
            'allocations' => $filtered->values(),
            'facility' => [
                'id' => $facility->id,
                'center_id' => $facility->center_id,
                'center_name' => $facility->center_name,
                'director' => $facility->owner ? [
                    'id' => $facility->owner->id,
                    'firstname' => $facility->owner->firstname,
                    'middlename' => $facility->owner->middlename,
                    'lastname' => $facility->owner->lastname,
                    'email' => $facility->owner->email,
                ] : null,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $summary,
            'message' => 'Facility fund dashboard data retrieved successfully.'
        ]);
    }

    /**
     * Generate the combined Fund Allocation Summary + Fund Management Report as PDF (download)
     */
    public function combinedReport(Request $request)
    {
        $user = Auth::user();

        // Allow finance and directors to generate reports only
        $role = strtolower($user->systemRole->name ?? '');
        if (!in_array($role, ['finance', 'director'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized role to generate fund reports.'
            ], 403);
        }

        // Resolve facility for current user
        $facility = null;
        if ($role === 'director') {
            $facility = FinancialAid::with('owner')->where('user_id', $user->id)->first();
        } elseif ($user->financial_aid_id) {
            $facility = FinancialAid::with('owner')->find($user->financial_aid_id);
        }

        if (!$facility) {
            return response()->json(['success' => false, 'message' => 'No facility found for the current user.'], 404);
        }

        [$summary, $allocations, $sponsorCount] = $this->buildFacilityFundData($facility->id);

        $data = [
            'facility' => [
                'center_id' => $facility->center_id,
                'center_name' => $facility->center_name,
            ],
            'summary' => $summary,
            'allocations' => $allocations,
            'sponsor_count' => $sponsorCount,
            'generated_by' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')) ?: 'System',
            'generated_at' => Carbon::now()->format('F d, Y h:i A'),
        ];

        $pdf = Pdf::loadView('pdf.fund-allocation-management', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'Combined_Fund_Report_' . ($facility->center_id ?: 'facility') . '_' . Carbon::now()->format('Ymd_His') . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Generate and email the combined report to all finance officers of the center
     */
    public function shareCombinedReport(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Allow finance and directors to share reports only
        $role = strtolower($user->systemRole->name ?? '');
        if (!in_array($role, ['finance', 'director'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized role to share fund reports.'
            ], 403);
        }

        // Resolve facility for current user
        $facility = null;
        if ($role === 'director') {
            $facility = FinancialAid::with('owner')->where('user_id', $user->id)->first();
        } elseif ($user->financial_aid_id) {
            $facility = FinancialAid::with('owner')->find($user->financial_aid_id);
        }

        if (!$facility) {
            return response()->json(['success' => false, 'message' => 'No facility found for the current user.'], 404);
        }

        [$summary, $allocations, $sponsorCount] = $this->buildFacilityFundData($facility->id);

        $data = [
            'facility' => [
                'center_id' => $facility->center_id,
                'center_name' => $facility->center_name,
            ],
            'summary' => $summary,
            'allocations' => $allocations,
            'sponsor_count' => $sponsorCount,
            'generated_by' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')) ?: 'System',
            'generated_at' => Carbon::now()->format('F d, Y h:i A'),
        ];

        $pdf = Pdf::loadView('pdf.fund-allocation-management', $data);
        $pdf->setPaper('A4', 'portrait');
        $binary = $pdf->output();
        $filename = 'Combined_Fund_Report_' . ($facility->center_id ?: 'facility') . '_' . Carbon::now()->format('Ymd_His') . '.pdf';

        // Finance officers in this facility
        $recipients = User::whereHas('systemRole', function ($q) {
                $q->where('name', 'finance');
            })
            ->where('financial_aid_id', $facility->id)
            ->get();

        $sent = 0;
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new CombinedFundReportNotification(
                    $facility->center_name ?? 'Facility',
                    $data['generated_by'],
                    $data['generated_at'],
                    $filename,
                    $binary
                ));
                $sent++;
            } catch (\Throwable $e) {
                \Log::warning('Failed to send combined fund report', [
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Report shared with finance officers.',
            'recipients' => $recipients->pluck('email'),
            'sent_count' => $sent,
            'file_name' => $filename,
        ]);
    }

    /**
     * Helper: build summary, allocations and sponsor count for a facility
     */
    private function buildFacilityFundData(int $facilityId): array
    {
        $allocations = FundAllocation::where('financial_aid_id', $facilityId)
            ->where('is_active', true)
            ->orderBy('fund_type')
            ->orderBy('sponsor_name')
            ->get();

        $allowedTypes = collect(['tuition', 'cola', 'other']);
        $filtered = $allocations->filter(function ($a) use ($allowedTypes) {
            return $allowedTypes->contains(strtolower($a->fund_type));
        });

        $summary = [
            'total_allocated' => $filtered->sum('allocated_amount'),
            'total_utilized' => $filtered->sum('utilized_amount'),
            'total_remaining' => $filtered->sum('remaining_amount'),
            'fund_types' => [
                'tuition' => [
                    'allocated' => $filtered->where('fund_type', 'tuition')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'tuition')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'tuition')->sum('remaining_amount'),
                ],
                'cola' => [
                    'allocated' => $filtered->where('fund_type', 'cola')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'cola')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'cola')->sum('remaining_amount'),
                ],
                'other' => [
                    'allocated' => $filtered->where('fund_type', 'other')->sum('allocated_amount'),
                    'utilized' => $filtered->where('fund_type', 'other')->sum('utilized_amount'),
                    'remaining' => $filtered->where('fund_type', 'other')->sum('remaining_amount'),
                ],
            ],
        ];

        $sponsorCount = $filtered->pluck('sponsor_name')->filter()->unique()->count();

        return [$summary, $filtered->values(), $sponsorCount];
    }
}
