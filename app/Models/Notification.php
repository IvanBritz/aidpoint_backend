<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Events\NotificationSent;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
        'priority',
        'category',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        // Broadcast notification when created
        static::created(function (Notification $notification) {
            broadcast(new NotificationSent($notification))->toOthers();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeUnread(Builder $query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead(Builder $query)
    {
        return $query->whereNotNull('read_at');
    }

    public function scopeByCategory(Builder $query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByPriority(Builder $query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeRecent(Builder $query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeFinancial(Builder $query)
    {
        return $query->where('category', 'financial');
    }

    // Helper methods
    public function markAsRead()
    {
        $this->update(['read_at' => now()]);
        return $this;
    }

    public function isRead()
    {
        return !is_null($this->read_at);
    }

    public function isUnread()
    {
        return is_null($this->read_at);
    }

    // Get priority color class
    public function getPriorityColorAttribute()
    {
        return match ($this->priority) {
            'low' => 'bg-gray-100 text-gray-800',
            'medium' => 'bg-blue-100 text-blue-800',
            'high' => 'bg-orange-100 text-orange-800',
            'urgent' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    // Get category color class
    public function getCategoryColorAttribute()
    {
        return match ($this->category) {
            'financial' => 'bg-green-100 text-green-800',
            'system' => 'bg-blue-100 text-blue-800',
            'user_management' => 'bg-purple-100 text-purple-800',
            'alert' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    // Enhanced helper method to create notifications
    public static function createForUser($userId, $type, $title, $message, $data = [], $priority = 'medium', $category = 'general')
    {
        return static::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'priority' => $priority,
            'category' => $category,
        ]);
    }

    /**
     * Create (idempotent) notification for subscription transaction lifecycle.
     * Ensures only one notification per user/type/reference.
     */
    public static function notifySubscriptionStatus(
        int $userId,
        string $reference,
        string $status, // pending|approved|completed|failed|refunded
        array $attrs = []
    ) {
        // Map status -> title/message/priority/category + action link
        $status = strtolower($status);
        $planName = $attrs['plan_name'] ?? 'Subscription';
        $amount = $attrs['amount'] ?? null;
        $action = $attrs['action_link'] ?? null;
        $now = now()->format('Y-m-d H:i:s');

        $humanAmount = $amount !== null ? ' for ₱' . number_format((float) $amount, 2) : '';
        $map = [
            'pending' => [
                'title' => 'Payment Pending',
                'message' => "We started processing your payment for {$planName}{$humanAmount}.",
                'priority' => 'medium',
            ],
            'approved' => [
                'title' => 'Payment Approved',
                'message' => "Your payment for {$planName}{$humanAmount} was approved.",
                'priority' => 'high',
            ],
            'completed' => [
                'title' => 'Subscription Active',
                'message' => "Your subscription is now active. Thanks for your payment{$humanAmount}.",
                'priority' => 'high',
            ],
            'failed' => [
                'title' => 'Payment Failed',
                'message' => "We couldn't complete your payment for {$planName}{$humanAmount}.",
                'priority' => 'urgent',
            ],
            'refunded' => [
                'title' => 'Payment Refunded',
                'message' => "Your payment for {$planName}{$humanAmount} was refunded.",
                'priority' => 'medium',
            ],
        ];

        $meta = $map[$status] ?? $map['pending'];
        $type = 'subscription_' . $status;

        // Idempotency: don't duplicate per reference+type
        $existing = static::where('user_id', $userId)
            ->where('type', $type)
            ->where('data->reference', $reference)
            ->first();
        if ($existing) {
            return $existing;
        }

        $data = array_merge($attrs, [
            'reference' => $reference,
            'status' => $status,
            'timestamp' => $now,
            'action_link' => $action,
        ]);

        return static::createForUser(
            $userId,
            $type,
            $meta['title'],
            $meta['message'],
            $data,
            $meta['priority'],
            'financial'
        );
    }

    // Finance-specific notification methods
    public static function notifyFundCreated($financeOfficerIds, $fundData, $priority = 'high')
    {
        $notifications = [];
        foreach ((array) $financeOfficerIds as $userId) {
            $notifications[] = static::createForUser(
                $userId,
                'fund_created',
                'New Fund Allocation Created',
                'New fund allocation: ' . ($fundData['sponsor_name'] ?? 'Unknown') . ' - ₱' . number_format($fundData['allocated_amount'] ?? 0, 2),
                $fundData,
                $priority,
                'financial'
            );
        }
        return $notifications;
    }

    public static function notifyDisbursementCreated($relevantUserIds, $disbursementData, $priority = 'high')
    {
        $notifications = [];
        foreach ((array) $relevantUserIds as $userId) {
            $notifications[] = static::createForUser(
                $userId,
                'disbursement_created',
                'New Disbursement Created',
                'New disbursement: ₱' . number_format($disbursementData['amount'] ?? 0, 2) . ' to ' . ($disbursementData['beneficiary_name'] ?? 'Unknown'),
                $disbursementData,
                $priority,
                'financial'
            );
        }
        return $notifications;
    }

    public static function notifyLiquidationPendingApproval($approverIds, $liquidationData, $priority = 'medium')
    {
        $notifications = [];
        foreach ((array) $approverIds as $userId) {
            $notifications[] = static::createForUser(
                $userId,
                'liquidation_pending',
                'Liquidation Pending Approval',
                'Liquidation awaiting your approval: ₱' . number_format($liquidationData['amount'] ?? 0, 2) . ' from ' . ($liquidationData['beneficiary_name'] ?? 'Unknown'),
                $liquidationData,
                $priority,
                'financial'
            );
        }
        return $notifications;
    }

    public static function notifyLiquidationStatusChange($beneficiaryId, $status, $liquidationData, $approverName, $priority = 'medium')
    {
        $statusText = ucfirst($status);
        $title = 'Liquidation ' . $statusText;
        $message = 'Your liquidation has been ' . strtolower($statusText) . ' by ' . $approverName;
        
        if ($status === 'approved') {
            $message .= '. Amount: ₱' . number_format($liquidationData['amount'] ?? 0, 2);
        }

        return static::createForUser(
            $beneficiaryId,
            'liquidation_' . strtolower($status),
            $title,
            $message,
            array_merge($liquidationData, ['approver_name' => $approverName]),
            $priority,
            'financial'
        );
    }

    public static function notifyLowFunds($userIds, $fundData, $priority = 'high')
    {
        $threshold = $fundData['threshold_percentage'] ?? 10;
        $notifications = [];
        foreach ((array) $userIds as $userId) {
            $notifications[] = static::createForUser(
                $userId,
                'low_funds_alert',
                'Low Fund Balance Alert',
                'Fund allocation "' . ($fundData['sponsor_name'] ?? 'Unknown') . '" has dropped below ' . $threshold . '% (Remaining: ₱' . number_format($fundData['remaining_amount'] ?? 0, 2) . ')',
                $fundData,
                $priority,
                'alert'
            );
        }
        return $notifications;
    }

    public static function notifySystemAlert($userIds, $alertData, $priority = 'urgent')
    {
        $notifications = [];
        foreach ((array) $userIds as $userId) {
            $notifications[] = static::createForUser(
                $userId,
                'system_alert',
                $alertData['title'] ?? 'System Alert',
                $alertData['message'] ?? 'System alert notification',
                $alertData,
                $priority,
                'alert'
            );
        }
        return $notifications;
    }

    // Bulk operations
    public static function markAllAsReadForUser($userId)
    {
        return static::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public static function getUnreadCountForUser($userId)
    {
        return static::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public static function getRecentNotificationsForUser($userId, $limit = 10)
    {
        return static::where('user_id', $userId)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Notify a user that their subscription has expired and must renew.
     */
    public static function notifySubscriptionExpired($userId, $attrs = [])
    {
        $action = $attrs['action_link'] ?? url('/app/subscriptions');
        return static::createForUser(
            $userId,
            'subscription_expired',
            'Subscription Expired',
            'Your subscription has expired. Please renew to regain access to the system.',
            array_merge($attrs, ['action_link' => $action]),
            'urgent',
            'financial'
        );
    }

    // Helper method to notify caseworker of new submission
    public static function notifyCaseworkerOfSubmission($caseworkerId, $beneficiaryName, $type = 'enrollment')
    {
        $typeText = $type === 'enrollment' ? 'enrollment verification' : 'aid request';
        
        return static::createForUser(
            $caseworkerId,
            $type === 'enrollment' ? 'new_submission' : 'new_aid_request',
            'New ' . ucfirst($typeText),
            "{$beneficiaryName} has submitted a new {$typeText} for review.",
            ['beneficiary_name' => $beneficiaryName],
            'medium',
            'user_management'
        );
    }
    
    // Helper method to notify caseworker of new beneficiary assignment
    public static function notifyCaseworkerOfAssignment($caseworkerId, $beneficiaryName, $beneficiaryId)
    {
        return static::createForUser(
            $caseworkerId,
            'beneficiary_assigned',
            'New Beneficiary Assigned',
            "{$beneficiaryName} has been assigned to you.",
            [
                'beneficiary_name' => $beneficiaryName,
                'beneficiary_id' => $beneficiaryId
            ],
            'medium',
            'user_management'
        );
    }

    // Helper method to notify beneficiary of review result
    public static function notifyBeneficiaryOfReview($beneficiaryId, $type, $status, $caseworkerName, $reviewNotes = null, $amount = null)
    {
        $typeText = $type === 'enrollment' ? 'enrollment verification' : 'aid request';
        $statusText = $status === 'approved' ? 'approved' : 'rejected';
        
        $title = ucfirst($typeText) . ' ' . ucfirst($statusText);
        $message = "Your {$typeText} has been {$statusText} by {$caseworkerName}.";
        
        $data = [
            'caseworker_name' => $caseworkerName,
            'review_notes' => $reviewNotes,
        ];
        
        if ($amount) {
            $data['amount'] = $amount;
        }

        return static::createForUser(
            $beneficiaryId,
            $type === 'enrollment' ? 'enrollment_' . $statusText : 'aid_' . $statusText,
            $title,
            $message,
            $data
        );
    }
    
    // Additional helper methods for specific user types
    public static function notifyBeneficiaryOfApplicationStatus($beneficiaryId, $applicationType, $status, $reviewerName, $notes = null)
    {
        $statusText = ucfirst($status);
        $title = ucfirst($applicationType) . ' ' . $statusText;
        $message = "Your {$applicationType} has been {$status} by {$reviewerName}.";
        
        if ($notes) {
            $message .= " Notes: {$notes}";
        }
        
        return static::createForUser(
            $beneficiaryId,
            $applicationType . '_' . strtolower($status),
            $title,
            $message,
            [
                'reviewer_name' => $reviewerName,
                'notes' => $notes,
                'application_type' => $applicationType
            ],
            'medium',
            'application'
        );
    }
    
    public static function notifyBeneficiaryOfDisbursement($beneficiaryId, $disbursementData)
    {
        return static::createForUser(
            $beneficiaryId,
            'disbursement_completed',
            'Disbursement Completed',
            'Your disbursement of ₱' . number_format($disbursementData['amount'], 2) . ' has been processed.',
            $disbursementData,
            'high',
            'financial'
        );
    }
    
    public static function notifyCaseworkersOfNewApplication($caseworkerIds, $applicationType, $beneficiaryName, $applicationData = [])
    {
        $notifications = [];
        foreach ((array) $caseworkerIds as $caseworkerId) {
            $notifications[] = static::createForUser(
                $caseworkerId,
                'new_' . $applicationType,
                'New ' . ucfirst(str_replace('_', ' ', $applicationType)),
                "{$beneficiaryName} has submitted a new {$applicationType} for review.",
                array_merge($applicationData, ['beneficiary_name' => $beneficiaryName]),
                'medium',
                'user_management'
            );
        }
        return $notifications;
    }
    
    public static function notifyDirectorOfHighValueTransaction($directorIds, $transactionData)
    {
        $notifications = [];
        foreach ((array) $directorIds as $directorId) {
            $notifications[] = static::createForUser(
                $directorId,
                'high_value_transaction',
                'High Value Transaction Alert',
                'A transaction of ₱' . number_format($transactionData['amount'], 2) . ' requires director approval.',
                $transactionData,
                'high',
                'financial'
            );
        }
        return $notifications;
    }

    // Caseworker: liquidation completed (fully approved and added to completed list)
    public static function notifyCaseworkerLiquidationCompleted(int $caseworkerId, array $liquidationData, string $priority = 'medium')
    {
        return static::createForUser(
            $caseworkerId,
            'liquidation_completed',
            'Liquidation Completed',
            'A liquidation for ' . ($liquidationData['beneficiary_name'] ?? 'a beneficiary') . ' has been fully approved and added to your completed list.',
            $liquidationData,
            $priority,
            'financial'
        );
    }

    // Finance: a new aid request has moved to finance review (pending fund request)
    public static function notifyFinancePendingAidRequest($financeUserIds, array $aidData, string $priority = 'high')
    {
        $notifications = [];
        foreach ((array) $financeUserIds as $id) {
            $notifications[] = static::createForUser(
                (int) $id,
                'finance_pending_aid_request',
                'Pending Fund Request',
                ($aidData['beneficiary_name'] ?? 'A beneficiary') . ' submitted a fund request that needs finance review.',
                $aidData,
                $priority,
                'financial'
            );
        }
        return $notifications;
    }

    // Finance: a request is fully approved and is ready for cash disbursement
    public static function notifyFinanceDisbursementReady($financeUserIds, array $aidData, string $priority = 'high')
    {
        $notifications = [];
        foreach ((array) $financeUserIds as $id) {
            $notifications[] = static::createForUser(
                (int) $id,
                'finance_disbursement_ready',
                'Cash Disbursement Ready',
                'Approved request for ' . ($aidData['beneficiary_name'] ?? 'beneficiary') . ' is ready to disburse.',
                $aidData,
                $priority,
                'financial'
            );
        }
        return $notifications;
    }

    // Finance: liquidation needs finance approval
    public static function notifyFinanceLiquidationPending($financeUserIds, array $liquidationData, string $priority = 'medium')
    {
        $notifications = [];
        foreach ((array) $financeUserIds as $id) {
            $notifications[] = static::createForUser(
                (int) $id,
                'finance_liquidation_pending',
                'Liquidation Pending Finance Approval',
                'Liquidation for ' . ($liquidationData['beneficiary_name'] ?? 'beneficiary') . ' needs finance approval.',
                $liquidationData,
                $priority,
                'financial'
            );
        }
        return $notifications;
    }

    // Finance: liquidation fully completed
    public static function notifyFinanceLiquidationCompleted($financeUserIds, array $liquidationData, string $priority = 'medium')
    {
        $notifications = [];
        foreach ((array) $financeUserIds as $id) {
            $notifications[] = static::createForUser(
                (int) $id,
                'finance_liquidation_completed',
                'Liquidation Completed',
                'A liquidation for ' . ($liquidationData['beneficiary_name'] ?? 'beneficiary') . ' has been fully approved.',
                $liquidationData,
                $priority,
                'financial'
            );
        }
        return $notifications;
    }
}
