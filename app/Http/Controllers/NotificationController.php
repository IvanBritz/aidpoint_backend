<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Get notifications for the authenticated user
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }
        
        $perPage = min($request->get('per_page', 15), 50);
        $category = $request->get('category');
        $priority = $request->get('priority');
        $readStatus = $request->get('read_status'); // 'read', 'unread', or null for all
        $days = $request->get('days', 30);

        $query = Notification::where('user_id', $user->id)
            ->orderByDesc('created_at');
            
        // Apply role-specific filtering if no specific category is requested
        $userRole = strtolower($user->systemRole?->name ?? '');
        if (!$category) {
            switch ($userRole) {
                case 'beneficiary':
                    // Beneficiaries see general/applications plus alerts/management items sent to them
                    $query->whereIn('category', ['general', 'application', 'alert', 'management']);
                    break;
                case 'caseworker':
                    // Caseworkers see notifications about submissions and reviews
                    $query->whereIn('category', ['general', 'user_management']);
                    break;
                case 'finance':
                    // Finance officers see financial notifications
                    $query->whereIn('category', ['financial', 'alert', 'general']);
                    break;
                case 'director':
                    // Directors see management-related notifications
                    $query->where(function ($q) {
                        $q->whereIn('category', ['management', 'approval', 'alert', 'general'])
                          ->orWhere(function ($subQ) {
                              $subQ->where('category', 'financial')
                                   ->whereIn('priority', ['high', 'critical']);
                          })
                          ->orWhere('type', 'like', '%facility%')
                          ->orWhere('type', 'like', '%employee%')
                          ->orWhere('type', 'like', '%approval%')
                          ->orWhere('type', 'like', '%liquidation%');
                    });
                    break;
            }
        }

        // Apply filters
        if ($category) {
            $query->where('category', $category);
        }

        if ($priority) {
            $query->where('priority', $priority);
        }

        if ($readStatus === 'read') {
            $query->whereNotNull('read_at');
        } elseif ($readStatus === 'unread') {
            $query->whereNull('read_at');
        }

        if ($days) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $notifications = $query->paginate($perPage);

        // Transform the data for frontend display
        $notifications->getCollection()->transform(function ($notification) {
            return [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'data' => $notification->data,
                'category' => $notification->category ?? 'general',
                'priority' => $notification->priority ?? 'medium',
                'priority_color' => $notification->priority_color ?? 'bg-blue-100 text-blue-800',
                'category_color' => $notification->category_color ?? 'bg-gray-100 text-gray-800',
                'is_read' => !is_null($notification->read_at),
                'read_at' => $notification->read_at?->format('Y-m-d H:i:s'),
                'created_at' => $notification->created_at->format('Y-m-d H:i:s'),
                'created_at_formatted' => $notification->created_at->format('M d, Y h:i A'),
                'time_ago' => $notification->created_at->diffForHumans(),
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $notifications,
            'message' => 'Notifications retrieved successfully.'
        ]);
    }

    /**
     * Get unread notification count
     */
    public function unreadCount(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }
        
        $count = Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
        
        // Get counts by category for finance users
        $categoryCounts = [];
        $userRole = strtolower($user->systemRole?->name ?? '');
        if (in_array($userRole, ['finance', 'director'])) {
            $categoryCounts = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->selectRaw('COALESCE(category, "general") as category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray();
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'total_unread' => $count,
                'category_counts' => $categoryCounts,
            ],
            'message' => 'Unread count retrieved successfully.'
        ]);
    }

    /**
     * Get recent notifications for dashboard display
     */
    public function recent(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        $limit = $request->get('limit', 5);
        
        $notifications = Notification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'category' => $notification->category ?? 'general',
                    'priority' => $notification->priority ?? 'medium',
                    'priority_color' => $notification->priority_color ?? 'bg-blue-100 text-blue-800',
                    'category_color' => $notification->category_color ?? 'bg-gray-100 text-gray-800',
                    'is_read' => !is_null($notification->read_at),
                    'created_at_formatted' => $notification->created_at->format('M d, Y h:i A'),
                    'time_ago' => $notification->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'message' => 'Recent notifications retrieved successfully.'
        ]);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead($id)
    {
        $user = Auth::user();
        $notification = Notification::where('user_id', $user->id)
            ->where('id', $id)
            ->first();
        
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.'
            ], 404);
        }
        
        if (!$notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }
        
        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => 'Notification marked as read.'
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        $user = Auth::user();
        $updated = Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
            'data' => ['updated_count' => $updated],
            'message' => 'All notifications marked as read.'
        ]);
    }

    /**
     * Delete a notification
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $notification = Notification::where('user_id', $user->id)
            ->where('id', $id)
            ->first();
        
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.'
            ], 404);
        }
        
        $notification->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Notification deleted.'
        ]);
    }
}
