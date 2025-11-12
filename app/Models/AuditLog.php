<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'event_category',
        'description',
        'event_data',
        'user_id',
        'user_name',
        'user_role',
        'entity_type',
        'entity_id',
        'ip_address',
        'user_agent',
        'risk_level',
    ];

    protected $casts = [
        'event_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes for filtering
    public function scopeByEventType(Builder $query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeByCategory(Builder $query, string $category)
    {
        return $query->where('event_category', $category);
    }

    public function scopeByUser(Builder $query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByEntity(Builder $query, string $entityType, int $entityId = null)
    {
        $query->where('entity_type', $entityType);
        if ($entityId) {
            $query->where('entity_id', $entityId);
        }
        return $query;
    }

    public function scopeByRiskLevel(Builder $query, string $riskLevel)
    {
        return $query->where('risk_level', $riskLevel);
    }

    public function scopeRecent(Builder $query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeFinancial(Builder $query)
    {
        return $query->where('event_category', 'financial');
    }

    // Helper methods for creating audit logs
    public static function logEvent(
        string $eventType,
        string $description,
        array $eventData = null,
        string $entityType = null,
        int $entityId = null,
        string $riskLevel = 'medium',
        string $category = 'financial'
    ) {
        $user = Auth::user();
        
        return self::create([
            'event_type' => $eventType,
            'event_category' => $category,
            'description' => $description,
            'event_data' => $eventData,
            'user_id' => $user?->id,
            'user_name' => $user ? trim(($user->firstname ?? '') . ' ' . ($user->middlename ?? '') . ' ' . ($user->lastname ?? '')) : null,
            'user_role' => $user?->systemRole?->name,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'risk_level' => $riskLevel,
        ]);
    }

    // Financial-specific audit log methods
    public static function logFundCreated(int $fundId, array $fundData, string $riskLevel = 'high')
    {
        return self::logEvent(
            'fund_created',
            'New fund allocation created: ' . ($fundData['sponsor_name'] ?? 'Unknown') . ' - ₱' . number_format($fundData['allocated_amount'] ?? 0, 2),
            $fundData,
            'fund_allocation',
            $fundId,
            $riskLevel
        );
    }

    public static function logFundUpdated(int $fundId, array $changes, string $riskLevel = 'high')
    {
        return self::logEvent(
            'fund_updated',
            'Fund allocation updated (ID: ' . $fundId . ')',
            $changes,
            'fund_allocation',
            $fundId,
            $riskLevel
        );
    }

    public static function logDisbursementCreated(int $disbursementId, array $disbursementData, string $riskLevel = 'high')
    {
        return self::logEvent(
            'disbursement_created',
            'New disbursement created: ₱' . number_format($disbursementData['amount'] ?? 0, 2) . ' to ' . ($disbursementData['beneficiary_name'] ?? 'Unknown'),
            $disbursementData,
            'disbursement',
            $disbursementId,
            $riskLevel
        );
    }

    public static function logDisbursementApproved(int $disbursementId, array $disbursementData, string $riskLevel = 'high')
    {
        return self::logEvent(
            'disbursement_approved',
            'Disbursement approved: ₱' . number_format($disbursementData['amount'] ?? 0, 2) . ' to ' . ($disbursementData['beneficiary_name'] ?? 'Unknown'),
            $disbursementData,
            'disbursement',
            $disbursementId,
            $riskLevel
        );
    }

    public static function logLiquidationSubmitted(int $liquidationId, array $liquidationData, string $riskLevel = 'medium')
    {
        return self::logEvent(
            'liquidation_submitted',
            'New liquidation submitted: ₱' . number_format($liquidationData['amount'] ?? 0, 2) . ' by ' . ($liquidationData['beneficiary_name'] ?? 'Unknown'),
            $liquidationData,
            'liquidation',
            $liquidationId,
            $riskLevel
        );
    }

    public static function logLiquidationApproved(int $liquidationId, array $liquidationData, string $riskLevel = 'high')
    {
        return self::logEvent(
            'liquidation_approved',
            'Liquidation approved: ₱' . number_format($liquidationData['amount'] ?? 0, 2) . ' from ' . ($liquidationData['beneficiary_name'] ?? 'Unknown'),
            $liquidationData,
            'liquidation',
            $liquidationId,
            $riskLevel
        );
    }

    public static function logLiquidationRejected(int $liquidationId, array $liquidationData, string $riskLevel = 'medium')
    {
        return self::logEvent(
            'liquidation_rejected',
            'Liquidation rejected: ₱' . number_format($liquidationData['amount'] ?? 0, 2) . ' from ' . ($liquidationData['beneficiary_name'] ?? 'Unknown'),
            $liquidationData,
            'liquidation',
            $liquidationId,
            $riskLevel
        );
    }

    public static function logUserLogin(string $riskLevel = 'low')
    {
        $user = Auth::user();
        return self::logEvent(
            'user_login',
            'User logged in: ' . ($user?->email ?? 'Unknown'),
            [
                'email' => $user?->email,
                'role' => $user?->systemRole?->name,
            ],
            'user',
            $user?->id,
            $riskLevel,
            'user_management'
        );
    }

    // Get formatted event data for display
    public function getFormattedEventDataAttribute()
    {
        if (!$this->event_data) {
            return null;
        }

        $data = [];
        foreach ($this->event_data as $key => $value) {
            if (is_numeric($value) && str_contains($key, 'amount')) {
                $data[ucwords(str_replace('_', ' ', $key))] = '₱' . number_format($value, 2);
            } else {
                $data[ucwords(str_replace('_', ' ', $key))] = $value;
            }
        }

        return $data;
    }

    // Get risk level color class
    public function getRiskLevelColorAttribute()
    {
        return match ($this->risk_level) {
            'low' => 'bg-gray-100 text-gray-800',
            'medium' => 'bg-blue-100 text-blue-800',
            'high' => 'bg-orange-100 text-orange-800',
            'critical' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    // Get event type color class
    public function getEventTypeColorAttribute()
    {
        return match (true) {
            str_contains($this->event_type, 'created') => 'bg-green-100 text-green-800',
            str_contains($this->event_type, 'updated') => 'bg-blue-100 text-blue-800',
            str_contains($this->event_type, 'approved') => 'bg-green-100 text-green-800',
            str_contains($this->event_type, 'rejected') => 'bg-red-100 text-red-800',
            str_contains($this->event_type, 'deleted') => 'bg-red-100 text-red-800',
            str_contains($this->event_type, 'login') => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
    
    // Beneficiary-specific audit log methods
    public static function logBeneficiaryLogin($userId)
    {
        return self::logEvent(
            'beneficiary_login',
            'Beneficiary logged into the system',
            ['login_time' => now()->toDateTimeString()],
            'user',
            $userId,
            'low',
            'user_management'
        );
    }
    
    public static function logEnrollmentSubmission($beneficiaryId, $submissionData)
    {
        return self::logEvent(
            'enrollment_submitted',
            'Beneficiary submitted enrollment verification documents',
            $submissionData,
            'enrollment',
            $submissionData['submission_id'] ?? null,
            'medium',
            'user_management'
        );
    }
    
    public static function logAidRequestSubmission($beneficiaryId, $requestData)
    {
        return self::logEvent(
            'aid_request_submitted',
            'Beneficiary submitted aid request for ₱' . number_format($requestData['amount'] ?? 0, 2),
            $requestData,
            'aid_request',
            $requestData['request_id'] ?? null,
            'medium',
            'user_management'
        );
    }
    
    // Caseworker-specific audit log methods
    public static function logCaseworkerReview($reviewData, $riskLevel = 'medium')
    {
        $action = $reviewData['status'] === 'approved' ? 'approved' : 'rejected';
        $type = $reviewData['type'] === 'enrollment' ? 'enrollment' : 'aid request';
        
        return self::logEvent(
            $reviewData['type'] . '_' . $action,
            "Caseworker {$action} {$type} from {$reviewData['beneficiary_name']}",
            $reviewData,
            $reviewData['type'],
            $reviewData['item_id'] ?? null,
            $riskLevel,
            'user_management'
        );
    }
    
    public static function logBeneficiaryAssignment($caseworkerId, $beneficiaryData)
    {
        return self::logEvent(
            'beneficiary_assigned',
            "Beneficiary {$beneficiaryData['beneficiary_name']} assigned to caseworker",
            $beneficiaryData,
            'beneficiary',
            $beneficiaryData['beneficiary_id'] ?? null,
            'low',
            'user_management'
        );
    }
}
