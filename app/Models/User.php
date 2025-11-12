<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\EmailVerificationCodeNotification;

class User extends Authenticatable implements MustVerifyEmailContract
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
        'is_first_login',
        'login_verification_code',
        'login_verification_code_expires_at',
        'requires_login_verification',
        'last_login_at',
        'login_attempt_count',
        'email_verification_code',
        'email_verification_code_expires_at',
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
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_scholar' => 'boolean',
            'is_first_login' => 'boolean',
            'login_verification_code_expires_at' => 'datetime',
            'requires_login_verification' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verification_code_expires_at' => 'datetime',
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

    /**
     * Generate and store a login verification code
     */
    public function generateLoginVerificationCode()
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $this->update([
            'login_verification_code' => $code,
            'login_verification_code_expires_at' => now()->addMinutes(10),
            'requires_login_verification' => true,
        ]);
        
        return $code;
    }

    /**
     * Verify the login verification code
     */
    public function verifyLoginCode($code)
    {
        if (!$this->login_verification_code || 
            !$this->login_verification_code_expires_at ||
            $this->login_verification_code_expires_at->isPast()) {
            return false;
        }
        
        if ($this->login_verification_code === $code) {
            $this->update([
                'login_verification_code' => null,
                'login_verification_code_expires_at' => null,
                'requires_login_verification' => false,
                'is_first_login' => false,
                'last_login_at' => now(),
                'login_attempt_count' => $this->login_attempt_count + 1,
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Check if login verification code is valid
     */
    public function hasValidLoginVerificationCode()
    {
        return $this->login_verification_code &&
               $this->login_verification_code_expires_at &&
               $this->login_verification_code_expires_at->isFuture();
    }

    /**
     * Mark user as needing login verification after first login
     */
    public function markForLoginVerification()
    {
        if (!$this->is_first_login) {
            $this->update([
                'is_first_login' => false,
                'last_login_at' => now(),
                'login_attempt_count' => $this->login_attempt_count + 1,
            ]);
        }
    }

    /**
     * Generate and store an email verification code
     */
    public function generateEmailVerificationCode()
    {
        // If there is an active code, reuse it (idempotent)
        if ($this->hasValidEmailVerificationCode()) {
            return $this->email_verification_code;
        }

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->update([
            'email_verification_code' => $code,
            // Extend validity to 1 month as requested
            'email_verification_code_expires_at' => now()->addMonth(),
        ]);
        return $code;
    }

    /**
     * Verify the email verification code
     */
    public function verifyEmailCode($code)
    {
        if (!$this->email_verification_code || 
            !$this->email_verification_code_expires_at ||
            $this->email_verification_code_expires_at->isPast()) {
            return false;
        }
        
        if ($this->email_verification_code === $code) {
            // Clear the verification code and mark email as verified
            $this->update([
                'email_verification_code' => null,
                'email_verification_code_expires_at' => null,
            ]);
            
            // Use the contract method to mark as verified
            $this->markEmailAsVerified();
            
            return true;
        }
        
        return false;
    }

    /**
     * Check if email verification code is valid
     */
    public function hasValidEmailVerificationCode()
    {
        return $this->email_verification_code &&
               $this->email_verification_code_expires_at &&
               $this->email_verification_code_expires_at->isFuture();
    }

    /**
     * Override the default email verification notification to send 6-digit codes
     */
    public function sendEmailVerificationNotification()
    {
        // Generate or reuse existing valid code and send
        $code = $this->generateEmailVerificationCode();
        $this->notify(new EmailVerificationCodeNotification($code));
    }

    /**
     * Send a custom email verification code (explicit method)
     */
    public function sendEmailVerificationCode()
    {
        $code = $this->generateEmailVerificationCode();
        $this->notify(new EmailVerificationCodeNotification($code));
    }

    /**
     * Determine if the user has verified their email address.
     */
    public function hasVerifiedEmail()
    {
        return ! is_null($this->email_verified_at);
    }

    /**
     * Mark the given user's email as verified.
     */
    public function markEmailAsVerified()
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Get the email address that should be used for verification.
     */
    public function getEmailForVerification()
    {
        return $this->email;
    }
}
