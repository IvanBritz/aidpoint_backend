<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiquidationReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'liquidation_id',
        'receipt_amount',
        'receipt_number',
        'receipt_date',
        'description',
        'verification_status',
        'verification_notes',
        'original_filename',
        'stored_filename',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'receipt_amount' => 'decimal:2',
        'receipt_date' => 'date',
        'file_size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    // Relationships
    public function liquidation()
    {
        return $this->belongsTo(Liquidation::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // Helper methods
    public function getFileSizeInMB()
    {
        return round($this->file_size / (1024 * 1024), 2);
    }

    public function getFileSizeFormatted()
    {
        $size = $this->file_size;
        
        if ($size < 1024) {
            return $size . ' B';
        } elseif ($size < 1024 * 1024) {
            return round($size / 1024, 2) . ' KB';
        } elseif ($size < 1024 * 1024 * 1024) {
            return round($size / (1024 * 1024), 2) . ' MB';
        } else {
            return round($size / (1024 * 1024 * 1024), 2) . ' GB';
        }
    }

    public function isImage()
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf()
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getFileExtension()
    {
        return pathinfo($this->original_filename, PATHINFO_EXTENSION);
    }
}