<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAidDocument extends Model
{
    use HasFactory;

    protected $table = 'financial_aid_document';

    protected $fillable = [
        'financial_aid_id',
        'document_type',
        'document_path',
    ];

    // Relationships
    public function financialAid()
    {
        return $this->belongsTo(FinancialAid::class);
    }
}
