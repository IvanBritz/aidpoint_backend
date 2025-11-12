<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAid extends Model
{
    use HasFactory;

    protected $table = 'financial_aid';

    protected $fillable = [
        'user_id',
        'center_id',
        'center_name',
        'longitude',
        'latitude',
        'description',
        'isManagable',
    ];

    protected $casts = [
        'isManagable' => 'boolean',
        'longitude' => 'decimal:8',
        'latitude' => 'decimal:8',
    ];

    // Relationships
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function documents()
    {
        return $this->hasMany(FinancialAidDocument::class);
    }

    // Helper methods
    public function isApproved()
    {
        return $this->isManagable;
    }

    public function approve()
    {
        $this->update(['isManagable' => true]);
    }

    public function reject()
    {
        $this->update(['isManagable' => false]);
    }
}
