<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
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
            $query->byCategory($category);
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
                // Caseworkers can see logs related to their assigned beneficiaries and their own actions
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('user_role', 'beneficiary')
                      ->orWhereIn('event_type', ['enrollment_submitted', 'aid_request_submitted', 'liquidation_submitted']);
                });
                // Focus on user management and general categories
                if (!$category) {
                    $query->whereIn('event_category', ['user_management', 'general', 'financial']);
                }
                break;
                
            case 'finance':
                // Finance officers see financial activities
                if (!$category) {
                    $query->where('event_category', 'financial');
                }
                break;
                
            case 'director':
                // Directors see activities related to facility management, approvals, and oversight
                if (!$category) {
                    $query->where(function ($q) {
                        $q->whereIn('event_category', ['user_management', 'financial', 'general'])
                          ->orWhereIn('event_type', [
                              'facility_approved', 'facility_updated', 'facility_rejected',
                              'employee_created', 'employee_updated', 'employee_deleted',
                              'beneficiary_enrolled', 'beneficiary_updated', 
                              'role_assigned', 'role_updated',
                              'fund_allocated', 'fund_updated', 'liquidation_approved', 'liquidation_rejected',
                              'final_approval_submitted', 'final_approval_completed'
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

        $options = [
            'event_types' => AuditLog::distinct()->pluck('event_type')->sort()->values()->toArray(),
            'categories' => AuditLog::distinct()->pluck('event_category')->sort()->values()->toArray(),
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