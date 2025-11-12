<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BeneficiaryDocumentSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'beneficiary_id',
        'enrollment_date',
        'year_level',
        'is_scholar',
        'enrollment_certification_path',
        'scholarship_certification_path',
        'sao_photo_path',
        'status',
        'reviewed_by',
        'review_notes',
        'reviewed_at',
    ];

    protected $casts = [
        'enrollment_date' => 'date',
        'reviewed_at' => 'datetime',
        'is_scholar' => 'boolean',
    ];

    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
