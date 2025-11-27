<?php

namespace App\Http\Controllers;

use App\Models\LiquidationReceipt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class LiquidationImageController extends Controller
{
    /**
     * Serve a receipt image for viewing (inline display)
     */
    public function viewReceiptImage($liquidationId, $receiptId)
    {
        $user = Auth::user();
        
        // Debug: Log the request
        \Log::info('Receipt image request', [
            'liquidationId' => $liquidationId,
            'receiptId' => $receiptId,
            'user_id' => $user->id,
            'user_role' => $user->systemRole?->name
        ]);
        
        // Get receipt with proper authorization checks
        $userRole = strtolower($user->systemRole->name ?? '');
        
        $receipt = LiquidationReceipt::whereHas('liquidation', function ($q) use ($user, $userRole) {
            // Authorization based on user role
            if ($userRole === 'beneficiary') {
                // Beneficiaries can only view their own receipts
                $q->where('beneficiary_id', $user->id);
            } elseif ($userRole === 'caseworker') {
                // Caseworkers can view receipts from their assigned beneficiaries
                // OR from completed/approved liquidations (for completed liquidations page)
                $q->where(function ($subQ) use ($user) {
                    $subQ->whereHas('beneficiary', function ($beneficiaryQuery) use ($user) {
                        $beneficiaryQuery->where('caseworker_id', $user->id);
                    })
                    ->orWhere(function ($completedQ) {
                        // Allow access to completed/approved liquidations for caseworkers
                        $completedQ->where('status', 'approved')
                                  ->whereNotNull('director_approved_at');
                    });
                });
            } elseif ($userRole === 'finance') {
                // Finance officers can view receipts from their facility
                $q->whereHas('beneficiary', function ($beneficiaryQuery) use ($user) {
                    $beneficiaryQuery->where('financial_aid_id', $user->financial_aid_id);
                });
            } elseif ($userRole === 'director') {
                // Directors can view receipts from their owned facility
                // Director's facility is linked via financial_aid.user_id = director.id
                $facility = \App\Models\FinancialAid::where('user_id', $user->id)->first();
                if ($facility) {
                    $q->whereHas('beneficiary', function ($beneficiaryQuery) use ($facility) {
                        $beneficiaryQuery->where('financial_aid_id', $facility->id);
                    });
                } else {
                    // Also try using the director's financial_aid_id if set
                    $q->whereHas('beneficiary', function ($beneficiaryQuery) use ($user) {
                        $beneficiaryQuery->where('financial_aid_id', $user->financial_aid_id);
                    });
                }
            } else {
                // No access for other roles
                $q->where('id', -1); // Force no results
            }
        })
        ->where('liquidation_id', $liquidationId)
        ->where('id', $receiptId)
        ->first();
        
        \Log::info('Receipt access attempt', [
            'user_role' => $user->systemRole?->name,
            'user_financial_aid_id' => $user->financial_aid_id,
            'liquidation_id' => $liquidationId,
            'receipt_id' => $receiptId,
            'receipt_found' => $receipt ? 'yes' : 'no'
        ]);

        if (!$receipt) {
            \Log::warning('Receipt not found or access denied', [
                'liquidation_id' => $liquidationId, 
                'receipt_id' => $receiptId,
                'user_id' => $user->id,
                'user_role' => $user->systemRole?->name,
                'caseworker_id' => $user->id
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found or access denied.',
                'debug' => [
                    'liquidation_id' => $liquidationId,
                    'receipt_id' => $receiptId,
                    'user_role' => $user->systemRole?->name,
                    'user_id' => $user->id
                ]
            ], 404);
        }

        \Log::info('Receipt found', [
            'file_path' => $receipt->file_path,
            'original_filename' => $receipt->original_filename,
            'mime_type' => $receipt->mime_type
        ]);

        // Check if file exists
        if (!Storage::disk('public')->exists($receipt->file_path)) {
            \Log::error('Receipt file not found in storage', [
                'file_path' => $receipt->file_path,
                'storage_path' => Storage::disk('public')->path($receipt->file_path)
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Receipt image file not found in storage.',
                'debug' => [
                    'file_path' => $receipt->file_path,
                    'full_path' => Storage::disk('public')->path($receipt->file_path),
                    'exists' => file_exists(Storage::disk('public')->path($receipt->file_path))
                ]
            ], 404);
        }

        try {
            // Get the file from storage
            $file = Storage::disk('public')->get($receipt->file_path);
            $mimeType = $receipt->mime_type ?: Storage::disk('public')->mimeType($receipt->file_path);

            // Return the image with appropriate headers for inline viewing
            return response($file)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline; filename="' . $receipt->original_filename . '"')
                ->header('Cache-Control', 'public, max-age=3600'); // Cache for 1 hour
        } catch (\Exception $e) {
            \Log::error('Error serving receipt image', [
                'error' => $e->getMessage(),
                'file_path' => $receipt->file_path
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error serving receipt image.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Debug endpoint to check receipt data and authorization
     */
    public function debugReceipt($liquidationId, $receiptId)
    {
        $user = Auth::user();
        $userRole = strtolower($user->systemRole->name ?? '');
        
        // First, get receipt without authorization
        $receipt = LiquidationReceipt::with('liquidation.beneficiary')
                                    ->where('liquidation_id', $liquidationId)
                                    ->where('id', $receiptId)
                                    ->first();
        
        // Then, check authorization
        $authorizedReceipt = LiquidationReceipt::whereHas('liquidation', function ($q) use ($user) {
            $userRole = strtolower($user->systemRole->name ?? '');
            
            if ($userRole === 'caseworker') {
                // Updated authorization for caseworkers
                $q->where(function ($subQ) use ($user) {
                    $subQ->whereHas('beneficiary', function ($beneficiaryQuery) use ($user) {
                        $beneficiaryQuery->where('caseworker_id', $user->id);
                    })
                    ->orWhere(function ($completedQ) {
                        // Allow access to completed/approved liquidations
                        $completedQ->where('status', 'approved')
                                  ->whereNotNull('director_approved_at');
                    });
                });
            }
        })
        ->where('liquidation_id', $liquidationId)
        ->where('id', $receiptId)
        ->first();
        
        return response()->json([
            'receipt_exists' => $receipt ? true : false,
            'authorized_access' => $authorizedReceipt ? true : false,
            'user' => [
                'id' => $user->id,
                'role' => $user->systemRole?->name,
                'financial_aid_id' => $user->financial_aid_id
            ],
            'receipt_data' => $receipt ? [
                'id' => $receipt->id,
                'liquidation_id' => $receipt->liquidation_id,
                'beneficiary_id' => $receipt->liquidation?->beneficiary_id,
                'beneficiary_caseworker_id' => $receipt->liquidation?->beneficiary?->caseworker_id,
                'file_path' => $receipt->file_path,
                'file_exists_in_storage' => Storage::disk('public')->exists($receipt->file_path),
                'full_storage_path' => Storage::disk('public')->path($receipt->file_path),
                'file_exists_on_disk' => file_exists(Storage::disk('public')->path($receipt->file_path))
            ] : null,
            'authorization_check' => [
                'user_role' => $userRole,
                'is_caseworker' => $userRole === 'caseworker',
                'user_id_matches_caseworker_id' => $receipt ? ($user->id === $receipt->liquidation?->beneficiary?->caseworker_id) : false
            ]
        ]);
    }
    
    /**
     * Generate a signed URL for receipt image access
     */
    public function generateSignedUrl($liquidationId, $receiptId)
    {
        $user = Auth::user();
        
        // Simple permission check - temporarily allow all authenticated users
        $receipt = LiquidationReceipt::where('liquidation_id', $liquidationId)
        ->where('id', $receiptId)
        ->first();
        
        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found'
            ], 404);
        }
        
        // Create a temporary signed URL (valid for 1 hour)
        $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'receipt.image.public',
            now()->addHour(),
            ['liquidationId' => $liquidationId, 'receiptId' => $receiptId]
        );
        
        return response()->json([
            'success' => true,
            'signed_url' => $signedUrl
        ]);
    }
    
    /**
     * Serve receipt image via signed URL (no authentication required)
     */
    public function viewReceiptImagePublic($liquidationId, $receiptId)
    {
        $receipt = LiquidationReceipt::where('liquidation_id', $liquidationId)
        ->where('id', $receiptId)
        ->first();
        
        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found'
            ], 404);
        }

        if (!Storage::disk('public')->exists($receipt->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt image file not found in storage.'
            ], 404);
        }

        try {
            $file = Storage::disk('public')->get($receipt->file_path);
            $mimeType = $receipt->mime_type ?: Storage::disk('public')->mimeType($receipt->file_path);

            return response($file)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline; filename="' . $receipt->original_filename . '"')
                ->header('Cache-Control', 'public, max-age=3600');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error serving receipt image.'
            ], 500);
        }
    }
}
