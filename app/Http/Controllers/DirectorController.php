<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\FundAllocation;
use App\Models\AidRequest;
use App\Models\Liquidation;
use App\Models\BeneficiaryDocumentSubmission;
use App\Models\AuditLog;
use App\Models\FinancialAid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DirectorController extends Controller
{
    /**
     * Get director dashboard overview data
     */
    public function facilityOverview()
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        // Check if user is director
        $userRole = strtolower($user->systemRole->name ?? '');
        if ($userRole !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Director privileges required.'
            ], 403);
        }

        try {
            // Determine the director's facility (owner) or fallback to assigned financial_aid_id
            $facility = FinancialAid::where('user_id', $user->id)->first();
            if (!$facility && $user->financial_aid_id) {
                $facility = FinancialAid::find($user->financial_aid_id);
            }
            
            if (!$facility) {
                return response()->json([
                    'success' => false,
                    'message' => 'No facility found for this director.'
                ], 404);
            }

            // Get staff count (employees under this facility)
            $staffCount = User::whereHas('systemRole', function ($query) {
                $query->whereIn('name', ['caseworker', 'finance']);
            })
            ->where('financial_aid_id', $facility->id)
            ->count();

            // Get beneficiary count (users marked as beneficiaries in this facility)
            $beneficiaryCount = User::where('financial_aid_id', $facility->id)
                ->where(function ($q) {
                    $q->where('systemrole_id', 4)
                      ->orWhereHas('systemRole', function ($query) {
                          $query->where('name', 'beneficiary');
                      });
                })
                ->count();

            // Get total aid disbursed
            $totalAidDisbursed = AidRequest::where('status', 'approved')
                ->whereHas('beneficiary', function ($query) use ($facility) {
                    $query->where('financial_aid_id', $facility->id);
                })
                ->sum('amount');

            // Get recent activities
            $recentActivities = AuditLog::where('created_at', '>=', Carbon::now()->subDays(7))
                ->whereIn('event_category', ['financial', 'user_management', 'general'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'facility' => [
                        'id' => $facility->id,
                        'center_id' => $facility->center_id,
                        'center_name' => $facility->center_name,
                        'location' => $facility->location,
                        'status' => $facility->isManagable ? 'active' : 'pending',
                        'isManagable' => (bool) $facility->isManagable,
                        'registration_date' => $facility->created_at,
                        'director' => [
                            'id' => $user->id,
                            'name' => trim(($user->firstname ?? '') . ' ' . ($user->middlename ?? '') . ' ' . ($user->lastname ?? '')),
                            'email' => $user->email
                        ]
                    ],
                    'staff_count' => $staffCount,
                    'beneficiary_count' => $beneficiaryCount,
                    'total_aid_disbursed' => $totalAidDisbursed,
                    'recent_activities' => $recentActivities->map(function ($activity) {
                        return [
                            'id' => $activity->id,
                            'event_type' => $activity->event_type,
                            'description' => $activity->description,
                            'user_name' => $activity->user_name,
                            'created_at' => $activity->created_at->format('Y-m-d H:i:s'),
                        ];
                    })
                ],
                'message' => 'Facility overview retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving facility overview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending approvals for director
     */
    public function pendingApprovals()
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        // Check if user is director
        $userRole = strtolower($user->systemRole->name ?? '');
        if ($userRole !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Director privileges required.'
            ], 403);
        }

        try {
            // Locate facility owned by (or assigned to) this director
            $facility = FinancialAid::where('user_id', $user->id)->first();
            if (!$facility && $user->financial_aid_id) {
                $facility = FinancialAid::find($user->financial_aid_id);
            }
            
            if (!$facility) {
                return response()->json([
                    'success' => false,
                    'message' => 'No facility found for this director.'
                ], 404);
            }

            $pendingApprovals = [];

            // Get pending liquidations that need director approval
            $pendingLiquidations = Liquidation::where('status', 'pending_director_approval')
                ->whereHas('beneficiary', function ($query) use ($facility) {
                    $query->where('financial_aid_id', $facility->id);
                })
                ->with(['beneficiary'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            foreach ($pendingLiquidations as $liquidation) {
                $pendingApprovals[] = [
                    'id' => $liquidation->id,
                    'type' => 'liquidation',
                    'amount' => (float) ($liquidation->total_disbursed_amount ?? 0),
                    'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                    'created_at' => $liquidation->created_at,
                    'description' => 'Liquidation approval required'
                ];
            }

            // Get pending aid requests that need director final approval
            $pendingAidRequests = AidRequest::where('status', 'pending')
                ->where('stage', 'director')
                ->where('finance_decision', 'approved')
                ->where('director_decision', 'pending')
                ->whereHas('beneficiary', function ($q) use ($facility) {
                    $q->where('financial_aid_id', $facility->id);
                })
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            foreach ($pendingAidRequests as $req) {
                $pendingApprovals[] = [
                    'id' => $req->id,
                    'type' => 'fund_request',
                    'amount' => (float) $req->amount,
                    'sponsor_name' => null,
                    'created_at' => $req->created_at,
                    'description' => 'Fund request final approval required'
                ];
            }

            // Sort by creation date
            usort($pendingApprovals, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            return response()->json([
                'success' => true,
                'data' => $pendingApprovals,
                'message' => 'Pending approvals retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving pending approvals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get staff performance metrics
     */
    public function staffPerformance(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        // Check if user is director
        $userRole = strtolower($user->systemRole->name ?? '');
        if ($userRole !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Director privileges required.'
            ], 403);
        }

        try {
            $facility = FinancialAid::where('user_id', $user->id)->first();
            if (!$facility && $user->financial_aid_id) {
                $facility = FinancialAid::find($user->financial_aid_id);
            }
            
            if (!$facility) {
                return response()->json([
                    'success' => false,
                    'message' => 'No facility found for this director.'
                ], 404);
            }

            $days = $request->get('days', 30);
            $startDate = Carbon::now()->subDays($days);

            // Get caseworker performance
            $caseworkers = User::whereHas('systemRole', function ($query) {
                $query->where('name', 'caseworker');
            })
            ->where('financial_aid_id', $facility->id)
            ->with(['assignedBeneficiaries'])->get();

            $caseworkerPerformance = [];
            foreach ($caseworkers as $caseworker) {
                // Count reviews completed
                $reviewsCompleted = BeneficiaryDocumentSubmission::where('reviewed_by', $caseworker->id)
                    ->where('created_at', '>=', $startDate)
                    ->count();

                // Count aid requests processed
                $aidRequestsProcessed = AidRequest::where('reviewed_by', $caseworker->id)
                    ->where('created_at', '>=', $startDate)
                    ->count();

                $caseworkerPerformance[] = [
                    'id' => $caseworker->id,
                    'name' => trim(($caseworker->firstname ?? '') . ' ' . ($caseworker->middlename ?? '') . ' ' . ($caseworker->lastname ?? '')),
                    'email' => $caseworker->email,
                    'assigned_beneficiaries' => $caseworker->assignedBeneficiaries->count(),
                    'reviews_completed' => $reviewsCompleted,
                    'aid_requests_processed' => $aidRequestsProcessed,
                    'total_activities' => $reviewsCompleted + $aidRequestsProcessed
                ];
            }

            // Sort by total activities
            usort($caseworkerPerformance, function ($a, $b) {
                return $b['total_activities'] - $a['total_activities'];
            });

            // Get finance officer activities
            $financeOfficers = User::whereHas('systemRole', function ($query) {
                $query->where('name', 'finance');
            })
            ->where('financial_aid_id', $facility->id)
            ->get();

            $financePerformance = [];
            foreach ($financeOfficers as $finance) {
                // Count fund allocations managed
                $fundAllocationsManaged = AuditLog::where('user_id', $finance->id)
                    ->where('event_type', 'fund_created')
                    ->where('created_at', '>=', $startDate)
                    ->count();

                // Count disbursements processed
                $disbursementsProcessed = AuditLog::where('user_id', $finance->id)
                    ->where('event_type', 'disbursement_created')
                    ->where('created_at', '>=', $startDate)
                    ->count();

                $financePerformance[] = [
                    'id' => $finance->id,
                    'name' => trim(($finance->firstname ?? '') . ' ' . ($finance->middlename ?? '') . ' ' . ($finance->lastname ?? '')),
                    'email' => $finance->email,
                    'fund_allocations_managed' => $fundAllocationsManaged,
                    'disbursements_processed' => $disbursementsProcessed,
                    'total_activities' => $fundAllocationsManaged + $disbursementsProcessed
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'period_days' => $days,
                    'caseworker_performance' => $caseworkerPerformance,
                    'finance_performance' => $financePerformance
                ],
                'message' => 'Staff performance data retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving staff performance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get complete dashboard data in a single optimized request
     * This endpoint combines all dashboard data to reduce round trips and improve load time
     */
    public function dashboardData(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        $userRole = strtolower($user->systemRole->name ?? '');
        if ($userRole !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Director privileges required.'
            ], 403);
        }

        try {
            // Prefer an explicitly requested facility (from URL/query), fall back to the user's assigned facility
            $requestedFacilityId = (int) ($request->input('facility_id') ?? 0);
            $facilityId = $requestedFacilityId ?: (int) $user->financial_aid_id;

            if (!$facilityId) {
                // Fallback: use facility owned by director
                $owned = FinancialAid::where('user_id', $user->id)->first();
                $facilityId = $owned?->id ?? 0;
            }

            if (!$facilityId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No facility specified or assigned to this director.'
                ], 404);
            }

            $facility = FinancialAid::find($facilityId);
            if (!$facility) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facility not found.'
                ], 404);
            }

            // Authorization: director must be assigned to this facility
            $hasAccess = ((int) ($facility->user_id ?? 0) === (int) $user->id)
                      || ((int) $user->financial_aid_id === (int) $facility->id);
            
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied for this facility.'
                ], 403);
            }

            // Count employees (caseworkers + finance) by financial_aid_id
            $staffCount = User::whereHas('systemRole', function ($query) {
                $query->whereIn('name', ['caseworker', 'finance']);
            })
            ->where('financial_aid_id', $facility->id)
            ->count();

            // Count beneficiaries by financial_aid_id
            $beneficiaryCount = User::where('financial_aid_id', $facility->id)
                ->where(function ($q) {
                    $q->where('systemrole_id', 4)
                      ->orWhereHas('systemRole', function ($query) {
                          $query->where('name', 'beneficiary');
                      });
                })
                ->count();

            // Count pending aid requests for director (final stage)
            $pendingAidApprovals = AidRequest::where('status', 'pending')
                ->where('stage', 'director')
                ->where('finance_decision', 'approved')
                ->where('director_decision', 'pending')
                ->whereHas('beneficiary', function ($q) use ($facility) {
                    $q->where('financial_aid_id', $facility->id);
                })
                ->count();

            // Count pending liquidation approvals for director
            $pendingLiquidationApprovals = Liquidation::where('status', 'pending_director_approval')
                ->whereHas('beneficiary', function ($q) use ($facility) {
                    $q->where('financial_aid_id', $facility->id);
                })
                ->count();

            // Fund allocations summary for the entire facility
            $allocations = FundAllocation::where('financial_aid_id', $facility->id)->get();

            $totalAllocated = (float) $allocations->sum('allocated_amount');
            $totalUtilized = (float) $allocations->sum('utilized_amount');
            $totalRemaining = (float) $allocations->sum('remaining_amount');

            // By fund type breakdown
            $fundTypesData = [];
            foreach ($allocations->groupBy('fund_type') as $type => $group) {
                $fundTypesData[$type] = [
                    'allocated' => (float) $group->sum('allocated_amount'),
                    'utilized'  => (float) $group->sum('utilized_amount'),
                ];
            }

            // Unique sponsors list
            $sponsors = $allocations->pluck('sponsor_name')->filter()->unique()->values()->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'facility' => [
                        'id' => $facility->id,
                        'center_id' => $facility->center_id,
                        'center_name' => $facility->center_name,
                        'location' => $facility->location,
                        'status' => $facility->isManagable ? 'active' : 'pending',
                        'isManagable' => (bool) $facility->isManagable,
                    ],
                    'stats' => [
                        'staff_count' => $staffCount,
                        'beneficiary_count' => $beneficiaryCount,
                        'pending_aid_approvals' => $pendingAidApprovals,
                        'pending_liquidation_approvals' => $pendingLiquidationApprovals,
                    ],
                    'finance' => [
                        'total_allocated' => $totalAllocated,
                        'total_utilized' => $totalUtilized,
                        'total_remaining' => $totalRemaining,
                        'fund_types' => $fundTypesData,
                        'sponsors' => $sponsors,
                    ]
                ],
                'message' => 'Dashboard data retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            \Log::error('Director dashboard error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get facility analytics summary
     */
    public function facilityAnalytics(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        // Check if user is director
        $userRole = strtolower($user->systemRole->name ?? '');
        if ($userRole !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Director privileges required.'
            ], 403);
        }

        try {
            $facility = FinancialAid::where('user_id', $user->id)->first();
            if (!$facility && $user->financial_aid_id) {
                $facility = FinancialAid::find($user->financial_aid_id);
            }
            
            if (!$facility) {
                return response()->json([
                    'success' => false,
                    'message' => 'No facility found for this director.'
                ], 404);
            }

            $days = $request->get('days', 30);
            $startDate = Carbon::now()->subDays($days);

            // Beneficiary statistics (users with beneficiary role in this facility)
            $totalBeneficiaries = User::where('financial_aid_id', $facility->id)
                ->where(function ($q) {
                    $q->where('systemrole_id', 4)
                      ->orWhereHas('systemRole', function ($query) {
                          $query->where('name', 'beneficiary');
                      });
                })->count();

            $newBeneficiaries = User::where('financial_aid_id', $facility->id)
                ->where(function ($q) {
                    $q->where('systemrole_id', 4)
                      ->orWhereHas('systemRole', function ($query) {
                          $query->where('name', 'beneficiary');
                      });
                })
                ->where('created_at', '>=', $startDate)
                ->count();

            // Aid request statistics
            $totalAidRequests = AidRequest::whereHas('beneficiary', function ($query) use ($facility) {
                $query->where('financial_aid_id', $facility->id);
            })->count();

            $approvedAidRequests = AidRequest::where('status', 'approved')
                ->whereHas('beneficiary', function ($query) use ($facility) {
                    $query->where('financial_aid_id', $facility->id);
                })
                ->count();

            $pendingAidRequests = AidRequest::where('status', 'pending')
                ->whereHas('beneficiary', function ($query) use ($facility) {
                    $query->where('financial_aid_id', $facility->id);
                })
                ->count();

            // Financial statistics
            $totalDisbursed = AidRequest::where('status', 'approved')
                ->whereHas('beneficiary', function ($query) use ($facility) {
                    $query->where('financial_aid_id', $facility->id);
                })
                ->sum('amount');

            $recentDisbursements = AidRequest::where('status', 'approved')
                ->whereHas('beneficiary', function ($query) use ($facility) {
                    $query->where('financial_aid_id', $facility->id);
                })
                ->where('updated_at', '>=', $startDate)
                ->sum('amount');

            // Fund allocation statistics for the facility
            $allocs = FundAllocation::where('financial_aid_id', $facility->id)->get();
            $totalFundAllocations = (float) $allocs->sum('allocated_amount');
            $utilizationRate = $totalFundAllocations > 0 ? ($totalDisbursed / $totalFundAllocations) * 100 : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'facility' => [
                        'id' => $facility->id,
                        'center_name' => $facility->center_name,
                        'location' => $facility->location,
                        'status' => $facility->isManagable ? 'active' : 'pending',
                        'isManagable' => (bool) $facility->isManagable
                    ],
                    'period_days' => $days,
                    'beneficiary_stats' => [
                        'total' => $totalBeneficiaries,
                        'new_this_period' => $newBeneficiaries,
                        'growth_rate' => $totalBeneficiaries > 0 ? ($newBeneficiaries / $totalBeneficiaries) * 100 : 0
                    ],
                    'aid_request_stats' => [
                        'total' => $totalAidRequests,
                        'approved' => $approvedAidRequests,
                        'pending' => $pendingAidRequests,
                        'approval_rate' => $totalAidRequests > 0 ? ($approvedAidRequests / $totalAidRequests) * 100 : 0
                    ],
                    'financial_stats' => [
                        'total_disbursed' => $totalDisbursed,
                        'recent_disbursements' => $recentDisbursements,
                        'total_allocated' => $totalFundAllocations,
                        'utilization_rate' => round($utilizationRate, 2)
                    ]
                ],
                'message' => 'Facility analytics retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving facility analytics: ' . $e->getMessage()
            ], 500);
        }
    }
}
