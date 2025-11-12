<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\\Database\\Factories\\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'firstname',
        'middlename',
        'lastname',
        'contact_number',
        'address',
        'email',
        'password',
        'must_change_password',
        'status',
        'systemrole_id',
        'financial_aid_id',
        'caseworker_id',
        'age', // kept for backward compatibility; age is derived from birthdate accessor
        'birthdate',
        'enrolled_school',
        'school_year',
        'is_scholar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_code_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_scholar' => 'boolean',
            'birthdate' => 'date',
        ];
    }

    public function systemRole()
    {
        return $this->belongsTo(SystemRole::class, 'systemrole_id');
    }

    public function financialAid()
    {
        return $this->belongsTo(FinancialAid::class, 'financial_aid_id');
    }

    public function caseworker()
    {
        return $this->belongsTo(User::class, 'caseworker_id');
    }

    public function assignedBeneficiaries()
    {
        return $this->hasMany(User::class, 'caseworker_id');
    }

    public function documentSubmissions()
    {
        return $this->hasMany(BeneficiaryDocumentSubmission::class, 'beneficiary_id');
    }

    public function aidRequests()
    {
        return $this->hasMany(AidRequest::class, 'beneficiary_id');
    }

    // Helper methods
    public function isAdmin()
    {
        return $this->systemRole->name === 'admin';
    }

    public function isDirector()
    {
        return $this->systemRole->name === 'director';
    }

    public function isEmployee()
    {
        return $this->systemRole->name === 'employee';
    }

    public function isBeneficiary()
    {
        return $this->systemRole->name === 'beneficiary';
    }

    public function isCaseworker()
    {
        return $this->systemRole->name === 'caseworker';
    }

    public function getFullNameAttribute()
    {
        return trim($this->firstname . ' ' . $this->middlename . ' ' . $this->lastname);
    }

    /**
     * Derived age based on birthdate; ignores stored 'age' column if present.
     */
    public function getAgeAttribute($value)
    {
        if ($this->birthdate) {
            try {
                $bd = \Carbon\Carbon::parse($this->birthdate);
                return $bd->age; // Carbon calculates age relative to current date
            } catch (\Throwable $e) {}
        }
        return $value; // fallback
    }

    /**
     * Generate or reuse a valid 5-digit email verification code.
     */
    public function issueEmailVerificationCode(int $ttlMinutes = 10): string
    {
        // Reuse if still valid
        if ($this->email_verification_code && $this->email_verification_code_expires_at && $this->email_verification_code_expires_at->isFuture()) {
            return $this->email_verification_code;
        }
        $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $this->forceFill([
            'email_verification_code' => $code,
            'email_verification_code_expires_at' => now()->addMinutes($ttlMinutes),
        ])->save();
        return $code;
    }

    /** Check if provided code is valid and consume it. */
    public function verifyEmailVerificationCode(string $code): bool
    {
        if (!$this->email_verification_code || !$this->email_verification_code_expires_at) {
            return false;
        }
        if ($this->email_verification_code_expires_at->isPast()) {
            return false;
        }
        if (hash_equals($this->email_verification_code, $code)) {
            $this->forceFill([
                'email_verification_code' => null,
                'email_verification_code_expires_at' => null,
            ])->save();
            return true;
        }
        return false;
    }

    public function hasValidEmailVerificationCode(): bool
    {
        return (bool) ($this->email_verification_code && $this->email_verification_code_expires_at && $this->email_verification_code_expires_at->isFuture());
    }

    // Subscription-related methods
    public function financialAidSubscriptions()
    {
        return $this->hasMany(FinancialAidSubscription::class);
    }

    public function hasHadFreePlan()
    {
        $freePlan = SubscriptionPlan::whereRaw('LOWER(plan_name) = ?', ['free'])->first();
        if (!$freePlan) {
            return false;
        }
        
        return $this->financialAidSubscriptions()
            ->where('plan_id', $freePlan->plan_id)
            ->exists();
    }

    public function hasActiveSubscription()
    {
        return $this->financialAidSubscriptions()
            ->where('status', 'Active')
            ->where('end_date', '>=', now()->toDateString())
            ->exists();
    }

    public function getCurrentSubscription()
    {
        return $this->financialAidSubscriptions()
            ->with('subscriptionPlan')
            ->where('status', 'Active')
            ->where('end_date', '>=', now()->toDateString())
            ->first();
    }

}
