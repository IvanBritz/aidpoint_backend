<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    /**
     * Get audit logs with filtering and pagination
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        // Check if user has permission to view audit logs (finance, director, admin, caseworker, beneficiary)
        $userRole = strtolower($user->systemRole->name ?? '');
        if (!in_array($userRole, ['finance', 'director', 'admin', 'caseworker', 'beneficiary'])) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Insufficient privileges to view audit logs.'
            ], 403);
        }

        $perPage = $request->get('per_page', 15);
        $eventType = $request->get('event_type');
        $category = $request->get('category');
        $riskLevel = $request->get('risk_level');
        $userId = $request->get('user_id');
        $entityType = $request->get('entity_type');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $search = $request->get('search');

        $query = AuditLog::with(['user:id,firstname,middlename,lastname,email'])
            ->orderByDesc('created_at');

        // Apply filters
        if ($eventType) {
            $query->byEventType($eventType);
        }

        if ($category) {
            $actorRoles = ['beneficiary','caseworker','finance','director','admin'];
            if (in_array(strtolower($category), $actorRoles)) {
                $query->whereRaw('LOWER(user_role) = ?', [strtolower($category)]);
            } else {
                $query->byCategory($category);
            }
        }

        if ($riskLevel) {
            $query->byRiskLevel($riskLevel);
        }

        if ($userId) {
            $query->byUser($userId);
        }

        if ($entityType) {
            $query->byEntity($entityType);
        }

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('event_type', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        // Apply role-specific filtering
        switch ($userRole) {
            case 'beneficiary':
                // Beneficiaries can only see logs related to their own activities
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere(function ($subQ) use ($user) {
                          $subQ->where('entity_type', 'beneficiary')
                               ->where('entity_id', $user->id);
                      });
                });
                // Limit categories to general activities
                if (!$category) {
                    $query->whereIn('event_category', ['general', 'user_management']);
                }
                break;
                
            case 'caseworker':
                // Caseworkers: restrict to logs for their assigned beneficiaries and their own actions
                $assignedBeneficiaryIds = User::where('caseworker_id', $user->id)
                    ->whereHas('systemRole', function ($q) {
                        $q->where('name', 'beneficiary');
                    })
                    ->pluck('id')
                    ->all();

                $query->where(function ($q) use ($user, $assignedBeneficiaryIds) {
                    // Caseworker's own actions
                    $q->where('user_id', $user->id)
                      // Beneficiaries assigned to the caseworker performing actions
                      ->orWhereIn('user_id', $assignedBeneficiaryIds)
                      // Logs whose entity explicitly references a beneficiary
                      ->orWhere(function ($sq) use ($assignedBeneficiaryIds) {
                          $sq->where('entity_type', 'beneficiary')
                             ->whereIn('entity_id', $assignedBeneficiaryIds);
                      })
                      // Financial/user-management events that include beneficiary_id in event_data
                      ->orWhere(function ($sq) use ($assignedBeneficiaryIds) {
                          foreach ($assignedBeneficiaryIds as $bid) {
                              $sq->orWhere('event_data->beneficiary_id', (int) $bid);
                          }
                      })
                      // Events where caseworker_id in event_data matches this caseworker (e.g., beneficiary assignments)
                      ->orWhere('event_data->caseworker_id', $user->id);
                });
                
                // Focus on relevant categories and include all liquidation/disbursement event types
                if (!$category) {
                    $query->where(function ($q) {
                        $q->whereIn('event_category', ['user_management', 'general', 'financial'])
                          ->orWhereIn('event_type', [
                              // Aid request workflow
                              'aid_request_submitted', 'aid_request_approved', 'aid_request_rejected',
                              'aid_request_caseworker_approved', 'aid_request_caseworker_rejected',
                              'aid_request_finance_approved', 'aid_request_finance_rejected',
                              'aid_request_director_approved', 'aid_request_director_rejected',
                              // Disbursement workflow
                              'disbursement_created', 'disbursement_released', 'disbursement_received',
                              'disbursement_caseworker_disbursed', 'disbursement_beneficiary_received',
                              // Liquidation workflow - ALL stages including finance and director
                              'liquidation_submitted', 'liquidation_approved', 'liquidation_rejected',
                              'liquidation_caseworker_approved', 'liquidation_caseworker_rejected',
                              'liquidation_finance_approved', 'liquidation_finance_rejected',
                              'liquidation_director_approved', 'liquidation_director_rejected',
                              // Beneficiary assignment
                              'beneficiary_assigned'
                          ]);
                    });
                }
                break;
                
            case 'finance':
                // Finance officers see financial activities for their center/facility
                $facilityId = $user->financial_aid_id;
                
                // Get all user IDs belonging to this facility (beneficiaries, caseworkers, etc.)
                $facilityUserIds = [];
                if ($facilityId) {
                    $facilityUserIds = User::where('financial_aid_id', $facilityId)
                        ->pluck('id')
                        ->all();
                    
                    // Also include the director (facility owner) who may not have financial_aid_id set
                    $facilityDirector = \App\Models\FinancialAid::where('id', $facilityId)->first();
                    if ($facilityDirector && $facilityDirector->user_id) {
                        $facilityUserIds[] = $facilityDirector->user_id;
                    }
                }
                
                // Filter by facility users and financial event types
                $query->where(function ($q) use ($user, $facilityUserIds) {
                    // Events performed by users in this facility
                    $q->whereIn('user_id', $facilityUserIds)
                      // Or events related to entities in this facility (via event_data)
                      ->orWhere(function ($sq) use ($facilityUserIds) {
                          foreach ($facilityUserIds as $uid) {
                              $sq->orWhere('event_data->beneficiary_id', (int) $uid);
                          }
                      });
                });
                
                // Focus on financial events: fund requests, liquidations, disbursements
                if (!$category) {
                    $query->where(function ($q) {
                        $q->where('event_category', 'financial')
                          ->orWhereIn('event_type', [
                              'fund_created', 'fund_updated', 'fund_allocated',
                              // Aid request workflow - all stages
                              'aid_request_submitted', 'aid_request_approved', 'aid_request_rejected',
                              'aid_request_caseworker_approved', 'aid_request_caseworker_rejected',
                              'aid_request_finance_approved', 'aid_request_finance_rejected',
                              'aid_request_director_approved', 'aid_request_director_rejected',
                              // Disbursement workflow
                              'disbursement_created', 'disbursement_released', 'disbursement_received',
                              'disbursement_caseworker_disbursed', 'disbursement_beneficiary_received',
                              // Liquidation workflow - all stages
                              'liquidation_submitted', 'liquidation_approved', 'liquidation_rejected',
                              'liquidation_caseworker_approved', 'liquidation_caseworker_rejected',
                              'liquidation_finance_approved', 'liquidation_finance_rejected',
                              'liquidation_director_approved', 'liquidation_director_rejected'
                          ]);
                    });
                }
                break;
                
            case 'director':
                // Directors see activities related to facility management, approvals, oversight, and subscriptions
                // Get the director's facility
                $directorFacility = \App\Models\FinancialAid::where('user_id', $user->id)->first();
                $directorFacilityId = $directorFacility?->id;
                
                // Get all user IDs belonging to this facility
                $directorFacilityUserIds = [];
                if ($directorFacilityId) {
                    $directorFacilityUserIds = User::where('financial_aid_id', $directorFacilityId)
                        ->pluck('id')
                        ->all();
                    // Also include the director themselves
                    $directorFacilityUserIds[] = $user->id;
                }
                
                // Filter by facility users
                if (!empty($directorFacilityUserIds)) {
                    $query->where(function ($q) use ($directorFacilityUserIds) {
                        $q->whereIn('user_id', $directorFacilityUserIds)
                          ->orWhere(function ($sq) use ($directorFacilityUserIds) {
                              foreach ($directorFacilityUserIds as $uid) {
                                  $sq->orWhere('event_data->beneficiary_id', (int) $uid);
                              }
                          });
                    });
                }
                
                if (!$category) {
                    $query->where(function ($q) {
                        $q->whereIn('event_category', ['user_management', 'financial', 'general', 'subscription'])
                          ->orWhereIn('event_type', [
                              'facility_approved', 'facility_updated', 'facility_rejected',
                              'employee_created', 'employee_updated', 'employee_deleted',
                              'beneficiary_enrolled', 'beneficiary_updated', 
                              'role_assigned', 'role_updated',
                              'fund_allocated', 'fund_updated', 'fund_created',
                              'aid_request_submitted', 'aid_request_approved', 'aid_request_rejected',
                              'aid_request_caseworker_approved', 'aid_request_caseworker_rejected',
                              'aid_request_finance_approved', 'aid_request_finance_rejected',
                              'aid_request_director_approved', 'aid_request_director_rejected',
                              'disbursement_created', 'disbursement_released', 'disbursement_received',
                              'disbursement_caseworker_disbursed', 'disbursement_beneficiary_received',
                              'liquidation_submitted', 'liquidation_approved', 'liquidation_rejected',
                              'liquidation_caseworker_approved', 'liquidation_caseworker_rejected',
                              'liquidation_finance_approved', 'liquidation_finance_rejected',
                              'liquidation_director_approved', 'liquidation_director_rejected',
                              'final_approval_submitted', 'final_approval_completed',
                              'subscription_created', 'subscription_upgraded', 'free_trial_activated', 'subscription_expired'
                          ]);
                    });
                }
                break;
                
            case 'admin':
                // Admins see everything
                break;
        }

        $auditLogs = $query->paginate($perPage);

        // Transform the data for frontend display
        $auditLogs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'event_type' => $log->event_type,
                'event_category' => $log->event_category,
                'description' => $log->description,
                'event_data' => $log->formatted_event_data,
                'user' => [
                    'id' => $log->user?->id,
                    'name' => $log->user_name,
                    'email' => $log->user?->email,
                    'role' => $log->user_role,
                ],
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'ip_address' => $log->ip_address,
                'risk_level' => $log->risk_level,
                'risk_level_color' => $log->risk_level_color,
                'event_type_color' => $log->event_type_color,
                'created_at' => $log->created_at->format('Y-m-d H:i:s'),
                'created_at_formatted' => $log->created_at->format('M d, Y h:i A'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $auditLogs,
            'message' => 'Audit logs retrieved successfully.'
        ]);
    }

    /**
     * Get audit log statistics
     */
    public function statistics(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        $userRole = strtolower($user->systemRole->name ?? '');
        if (!in_array($userRole, ['finance', 'director', 'admin', 'caseworker', 'beneficiary'])) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Insufficient privileges to view audit statistics.'
            ], 403);
        }

        $days = $request->get('days', 30);
        $startDate = now()->subDays($days);

        $stats = [
            'total_events' => AuditLog::where('created_at', '>=', $startDate)->count(),
            'events_by_risk_level' => AuditLog::where('created_at', '>=', $startDate)
                ->selectRaw('risk_level, COUNT(*) as count')
                ->groupBy('risk_level')
                ->pluck('count', 'risk_level')
                ->toArray(),
            'events_by_category' => AuditLog::where('created_at', '>=', $startDate)
                ->selectRaw('event_category, COUNT(*) as count')
                ->groupBy('event_category')
                ->pluck('count', 'event_category')
                ->toArray(),
            'events_by_type' => AuditLog::where('created_at', '>=', $startDate)
                ->selectRaw('event_type, COUNT(*) as count')
                ->groupBy('event_type')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'event_type')
                ->toArray(),
            'recent_high_risk_events' => AuditLog::with(['user:id,firstname,middlename,lastname'])
                ->where('created_at', '>=', $startDate)
                ->whereIn('risk_level', ['high', 'critical'])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'event_type' => $log->event_type,
                        'description' => $log->description,
                        'user_name' => $log->user_name,
                        'risk_level' => $log->risk_level,
                        'risk_level_color' => $log->risk_level_color,
                        'created_at' => $log->created_at->format('M d, Y h:i A'),
                    ];
                }),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Audit statistics retrieved successfully.'
        ]);
    }

    /**
     * Get available filter options
     */
    public function filterOptions()
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        $userRole = strtolower($user->systemRole->name ?? '');
        if (!in_array($userRole, ['finance', 'director', 'admin', 'caseworker', 'beneficiary'])) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Insufficient privileges to view filter options.'
            ], 403);
        }

        $eventTypes = AuditLog::distinct()->pluck('event_type')->sort()->values()->toArray();
        $staticEventTypes = [
            'liquidation_caseworker_approved',
            'liquidation_director_approved',
        ];
        $eventTypes = array_values(array_unique(array_merge($eventTypes, $staticEventTypes)));

        $options = [
            'event_types' => $eventTypes,
            'categories' => AuditLog::distinct()->pluck('event_category')->sort()->values()->toArray(),
            'actor_roles' => AuditLog::distinct()->pluck('user_role')->filter()->map(fn($r) => strtolower($r))->unique()->sort()->values()->toArray(),
            'risk_levels' => ['low', 'medium', 'high', 'critical'],
            'entity_types' => AuditLog::distinct()->whereNotNull('entity_type')->pluck('entity_type')->sort()->values()->toArray(),
        ];

        return response()->json([
            'success' => true,
            'data' => $options,
            'message' => 'Filter options retrieved successfully.'
        ]);
    }
}
