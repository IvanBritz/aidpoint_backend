<?php

namespace App\Http\Controllers;

use App\Models\Liquidation;
use App\Models\LiquidationReceipt;
use App\Models\Disbursement;
use App\Models\User;
use App\Models\Notification;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\File;
use App\Services\LiquidationSummaryService;

class LiquidationController extends Controller
{
    /**
     * Get liquidations for the current beneficiary
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json([
                'success' => false,
                'message' => 'Only beneficiaries can access their liquidations.'
            ], 403);
        }

        $perPage = $request->get('per_page', 10);
        
        // Show only the latest liquidation per disbursement (hide previous attempts)
        $latestIds = Liquidation::selectRaw('MAX(id) as id')
            ->where('beneficiary_id', $user->id)
            ->groupBy('disbursement_id')
            ->pluck('id');
        
        $liquidations = Liquidation::with(['receipts', 'disbursement.aidRequest', 'reviewer'])
            ->whereIn('id', $latestIds)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // Format the data for frontend consumption
        $liquidations->getCollection()->transform(function ($liquidation) {
            $liquidation->makeHidden(['disbursement', 'reviewer']);
            $liquidation->receipts_count = $liquidation->receipts->count();
            $liquidation->receipts->makeHidden(['file_path', 'stored_filename']);
            return $liquidation;
        });

        return response()->json([
            'success' => true,
            'data' => $liquidations,
            'message' => 'Liquidations retrieved successfully.'
        ]);
    }

    /**
     * Store a new liquidation with receipts
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json([
                'success' => false,
                'message' => 'Only beneficiaries can submit liquidations.'
            ], 403);
        }

        // Laravel 12 formats some validation messages using Number::ordinal(), which requires the intl PHP extension.
        // On Windows setups where intl may be disabled, calling Request::validate can crash with
        // "The 'intl' PHP extension is required to use the (ordinal) method." when validating array inputs.
        // To keep the feature working without forcing environment changes, we add a lightweight fallback
        // validator that avoids ordinalized messages when intl is unavailable.
        if (!extension_loaded('intl')) {
            // Manual validation (minimal but safe). Returns concise messages without ordinal formatting.
            $data = $request->all();

            // disbursement_id
            if (!isset($data['disbursement_id']) || !is_numeric($data['disbursement_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'The disbursement_id field is required and must be a valid ID.'
                ], 422);
            }

            // disbursement type
            $allowedTypes = ['tuition', 'cola', 'other'];
            if (!isset($data['disbursement']) || !in_array(strtolower((string) $data['disbursement']), $allowedTypes, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid disbursement type. Allowed: tuition, cola, other.'
                ], 422);
            }

            // receipts basic checks
            if (!isset($data['receipts']) || !is_array($data['receipts']) || count($data['receipts']) < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please attach at least one receipt.'
                ], 422);
            }
            if (count($data['receipts']) > 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can upload at most 10 receipts at a time.'
                ], 422);
            }

            // Per-receipt checks
            foreach ($data['receipts'] as $i => $receipt) {
                // file
                if (!isset($receipt['file']) || !$receipt['file']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Each receipt must include a file upload.'
                    ], 422);
                }
                $file = $receipt['file'];
                $ext = strtolower($file->getClientOriginalExtension() ?? '');
                if (!in_array($ext, ['pdf'], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Receipt files must be PDF documents (.pdf) only.'
                    ], 422);
                }
                // size (<= 5 MB)
                if ($file->getSize() > 5 * 1024 * 1024) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Receipt file size must not exceed 5 MB.'
                    ], 422);
                }

                // amount
                if (!isset($receipt['amount']) || !is_numeric($receipt['amount']) || (float) $receipt['amount'] < 0.01) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Each receipt must have a valid amount of at least 0.01.'
                    ], 422);
                }

                // date
                if (!isset($receipt['receipt_date']) || empty($receipt['receipt_date'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Each receipt must include a receipt_date.'
                    ], 422);
                }
                try {
                    // Just ensure it parses; the precise allowed window is validated below
                    \Carbon\Carbon::parse($receipt['receipt_date']);
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid receipt_date format.'
                    ], 422);
                }
            }
        } else {
            // Standard Laravel validation path (uses nicer error messages when intl is available)
            $request->validate([
                'disbursement_id' => ['required', 'integer', 'exists:disbursements,id'],
                'disbursement' => ['required', 'string', 'in:tuition,cola,other'],
                'description' => ['nullable', 'string', 'max:1000'],
                'receipts' => ['required', 'array', 'min:1', 'max:10'],
                'receipts.*.file' => [
                    'required',
                    File::types(['pdf'])
                        ->max('5mb'),
                ],
                'receipts.*.amount' => ['required', 'numeric', 'min:0.01'],
                'receipts.*.receipt_number' => ['nullable', 'string', 'max:255'],
'receipts.*.receipt_date' => ['required', 'date'],
                'receipts.*.description' => ['nullable', 'string', 'max:500'],
            ]);
        }

        try {
            DB::beginTransaction();

            // Get the disbursement record
            $disbursement = Disbursement::where('id', $request->disbursement_id)
                ->whereHas('aidRequest', function ($q) use ($user) {
                    $q->where('beneficiary_id', $user->id);
                })
                ->first();
                
            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid disbursement selected or you do not have permission to liquidate this disbursement.'
                ], 422);
            }
            
            // Validate receipt dates are within the appropriate month
            // Priority 1: Explicit month/year on the aid request (e.g., COLA)
            $aidRequest = $disbursement->aidRequest;
            if ($aidRequest && $aidRequest->month && $aidRequest->year) {
                $requestMonth = $aidRequest->month;
                $requestYear = $aidRequest->year;
                $windowStart = \Carbon\Carbon::create($requestYear, $requestMonth, 1)->startOfMonth();
                $windowEnd = (clone $windowStart)->endOfMonth();
                
                foreach ($request->receipts as $index => $receiptData) {
                    $receiptDate = \Carbon\Carbon::parse($receiptData['receipt_date']);
                    if ($receiptDate->lt($windowStart) || $receiptDate->gt($windowEnd)) {
                        return response()->json([
                            'success' => false,
                            'message' => "Receipt #" . ($index + 1) . " date (" . $receiptDate->format('F j, Y') . ") must be within the requested fund period: " . $windowStart->format('F j') . " - " . $windowEnd->format('F j, Y') . "."
                        ], 422);
                    }
                }
            }
            // Priority 2: If no explicit month/year, use the beneficiary cash received date month
            elseif (!empty($disbursement->beneficiary_received_at)) {
                $receivedAt = \Carbon\Carbon::parse($disbursement->beneficiary_received_at);
                $windowStart = (clone $receivedAt)->startOfMonth();
                $windowEnd = (clone $receivedAt)->endOfMonth();
                
                foreach ($request->receipts as $index => $receiptData) {
                    $receiptDate = \Carbon\Carbon::parse($receiptData['receipt_date']);
                    if ($receiptDate->lt($windowStart) || $receiptDate->gt($windowEnd)) {
                        return response()->json([
                            'success' => false,
                            'message' => "Receipt #" . ($index + 1) . " date (" . $receiptDate->format('F j, Y') . ") must be within the month the cash was received: " . $windowStart->format('F j') . " - " . $windowEnd->format('F j, Y') . "."
                        ], 422);
                    }
                }
            }
            // Otherwise (no explicit month constraint available), accept any valid date

            // Calculate total receipt amount
            $totalReceiptAmount = collect($request->receipts)->sum('amount');
            $totalDisbursedAmount = $disbursement->amount;
            
            // Business rule: Receipts may be equal to or greater than the disbursed amount.
            // Do NOT block when total exceeds; remaining will be clamped to 0 and marked complete.
            // If needed, reviewers can flag overages during approval.
            
            // Create the liquidation record
            $liquidation = Liquidation::create([
                'disbursement_id' => $disbursement->id,
                'beneficiary_id' => $user->id,
                'liquidation_date' => now()->toDateString(), // Use current date for liquidation submission
                'disbursement_type' => $request->disbursement,
                'or_invoice_no' => 'Multiple receipts', // Will be updated based on receipts
                'total_disbursed_amount' => $totalDisbursedAmount,
                'total_receipt_amount' => 0, // Will be calculated after receipts are added
                'remaining_amount' => $totalDisbursedAmount,
                'is_complete' => false,
                'description' => $request->description,
                'status' => 'in_progress',
            ]);

            // Handle receipt uploads
            $uploadDir = 'liquidation-receipts/' . $user->id . '/' . $liquidation->id;
            $uploadedReceipts = [];

            foreach ($request->receipts as $index => $receiptData) {
                $file = $receiptData['file'];
                $originalName = $file->getClientOriginalName();
                $storedName = time() . '_' . $index . '.' . $file->getClientOriginalExtension();
                $filePath = $file->storeAs($uploadDir, $storedName, 'public');

                $receipt = LiquidationReceipt::create([
                    'liquidation_id' => $liquidation->id,
                    'receipt_amount' => $receiptData['amount'],
                    'receipt_number' => $receiptData['receipt_number'] ?? null,
                    'receipt_date' => $receiptData['receipt_date'],
                    'description' => $receiptData['description'] ?? null,
                    'verification_status' => 'pending',
                    'original_filename' => $originalName,
                    'stored_filename' => $storedName,
                    'file_path' => $filePath,
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $user->id,
                    'uploaded_at' => now(),
                ]);

                $uploadedReceipts[] = $receipt;
            }
            
            // Update liquidation totals
            $liquidation->updateReceiptTotals();
            
            // Update disbursement liquidation status
            $disbursement->updateLiquidationStatus();

            DB::commit();

            // Notification to caseworker will be sent upon explicit submission for approval

            $liquidation->load('receipts');
            $liquidation->receipts->makeHidden(['file_path', 'stored_filename']);

            return response()->json([
                'success' => true,
                'data' => $liquidation,
                'message' => 'Liquidation submitted successfully with ' . count($uploadedReceipts) . ' receipt(s).'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit liquidation.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get liquidations for caseworker review
     */
    public function forCaseworker(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json([
                'success' => false,
                'message' => 'Only caseworkers can access liquidations for review.'
            ], 403);
        }

        $perPage = $request->get('per_page', 10);
        $status = $request->get('status', 'pending');

        $query = Liquidation::with(['beneficiary', 'receipts', 'disbursement.aidRequest'])
            ->whereHas('beneficiary', function ($q) use ($user) {
                $q->where('caseworker_id', $user->id);
            });

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $liquidations = $query->orderByDesc('created_at')->paginate($perPage);

        // Format the data for frontend
        $liquidations->getCollection()->transform(function ($liquidation) {
            $liquidation->receipts_count = $liquidation->receipts->count();
            $liquidation->receipts->makeHidden(['file_path', 'stored_filename']);
            $liquidation->beneficiary->makeHidden(['password', 'remember_token', 'email_verified_at']);
            return $liquidation;
        });

        return response()->json([
            'success' => true,
            'data' => $liquidations,
            'message' => 'Liquidations retrieved successfully.'
        ]);
    }

    /**
     * Review a liquidation (approve or reject)
     */
    public function review(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'caseworker') {
            return response()->json([
                'success' => false,
                'message' => 'Only caseworkers can review liquidations.'
            ], 403);
        }

        $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'reviewer_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $liquidation = Liquidation::with('beneficiary')->findOrFail($id);

        // Ensure this caseworker is assigned to the beneficiary
        if (!$liquidation->beneficiary || $liquidation->beneficiary->caseworker_id !== $user->id) {
            try {
                AuditLog::logEvent(
                    'liquidation_caseworker_unauthorized_attempt',
                    'Unauthorized caseworker liquidation review attempt',
                    [
                        'liquidation_id' => $liquidation->id,
                        'attempted_by' => $user->id,
                        'beneficiary_id' => $liquidation->beneficiary?->id,
                    ],
                    'liquidation',
                    $liquidation->id,
                    'critical',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to review this liquidation.'
            ], 403);
        }

        if ($liquidation->status !== 'pending') {
            try {
                AuditLog::logEvent(
                    'liquidation_invalid_status_attempt',
                    'Caseworker attempted liquidation review with invalid status',
                    [
                        'liquidation_id' => $liquidation->id,
                        'current_status' => $liquidation->status,
                    ],
                    'liquidation',
                    $liquidation->id,
                    'high',
                    'financial'
                );
            } catch (\Throwable $e) { }
            return response()->json([
                'success' => false,
                'message' => 'Only pending liquidations can be reviewed.'
            ], 422);
        }

        $status = $request->action === 'approve' ? 'approved' : 'rejected';
        
        // Rejection requires notes
        if ($status === 'rejected' && empty($request->reviewer_notes)) {
            return response()->json([
                'success' => false,
                'message' => 'Rejection reason is required when rejecting a liquidation.'
            ], 422);
        }

        $liquidation->update([
            'status' => $status,
            'reviewed_by' => $user->id,
            'reviewer_notes' => $request->reviewer_notes,
            'reviewed_at' => now(),
        ]);

        // Notify the beneficiary of the review result
        try {
            Notification::create([
                'type' => 'liquidation_reviewed',
                'data' => json_encode([
                    'liquidation_id' => $liquidation->id,
                    'status' => $status,
                    'reviewer_name' => $user->firstname . ' ' . $user->lastname,
                    'reviewer_notes' => $request->reviewer_notes,
                    'amount' => $liquidation->amount,
                    'disbursement_type' => $liquidation->disbursement_type,
                ]),
                'notifiable_type' => User::class,
                'notifiable_id' => $liquidation->beneficiary_id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to create liquidation review notification', [
                'error' => $e->getMessage(),
                'liquidation_id' => $liquidation->id,
            ]);
        }

        try {
            $eventType = $status === 'approved' ? 'liquidation_caseworker_approved' : 'liquidation_caseworker_rejected';
            $desc = $status === 'approved'
                ? ('Caseworker approved liquidation for ' . trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')))
                : ('Caseworker rejected liquidation for ' . trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')));
            AuditLog::logEvent(
                $eventType,
                $desc,
                [
                    'liquidation_id' => $liquidation->id,
                    'beneficiary_id' => $liquidation->beneficiary_id,
                    'beneficiary_name' => trim(($liquidation->beneficiary->firstname ?? '') . ' ' . ($liquidation->beneficiary->lastname ?? '')),
                    'notes' => $request->reviewer_notes,
                ],
                'liquidation',
                $liquidation->id,
                $status === 'approved' ? 'medium' : 'medium',
                'financial'
            );
        } catch (\Throwable $e) { }

        $liquidation->load('reviewer');

        return response()->json([
            'success' => true,
            'data' => $liquidation,
            'message' => 'Liquidation has been ' . $status . ' successfully.'
        ]);
    }

    /**
     * Get available disbursements for a beneficiary to liquidate
     */
    public function availableDisbursements(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->systemRole || strtolower($user->systemRole->name) !== 'beneficiary') {
            return response()->json([
                'success' => false,
                'message' => 'Only beneficiaries can access their disbursements.'
            ], 403);
        }

        // Debug: Log user info
        \Log::info('Available disbursements requested by user', ['user_id' => $user->id, 'role' => $user->systemRole?->name]);

        // First, get all disbursements for this user to debug
        $allDisbursements = Disbursement::with(['aidRequest'])
            ->whereHas('aidRequest', function ($q) use ($user) {
                $q->where('beneficiary_id', $user->id);
            })
            ->get();
            
        \Log::info('All disbursements for user', ['count' => $allDisbursements->count(), 'disbursements' => $allDisbursements->toArray()]);

        $disbursements = Disbursement::with(['aidRequest'])
            ->whereHas('aidRequest', function ($q) use ($user) {
                $q->where('beneficiary_id', $user->id);
            })
            ->where('status', 'beneficiary_received') // Only completed disbursements can be liquidated
            // Allow re-liquidation: beneficiaries may submit additional receipts if not fully liquidated yet.
            // Exclude fully liquidated items. Allow items with unknown/zero remaining if nothing has been liquidated yet.
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('fully_liquidated')
                      ->orWhere('fully_liquidated', false);
                })
                ->where(function ($q) {
                    $q->whereNull('remaining_to_liquidate')
                      ->orWhere('remaining_to_liquidate', '>', 0.01)
                      ->orWhere(function ($sub) {
                          $sub->where('remaining_to_liquidate', '=', 0)
                              ->where(function ($s) {
                                  $s->whereNull('liquidated_amount')
                                    ->orWhere('liquidated_amount', '=', 0);
                              });
                      });
                });
            })
            ->orderByDesc('beneficiary_received_at')
            ->get();
            
        \Log::info('Filtered disbursements for liquidation', ['count' => $disbursements->count(), 'disbursements' => $disbursements->toArray()]);

        // Format for dropdown selection (safety filter mirrors DB logic and also allows zero-remaining
        // when nothing has been liquidated yet)
        $disbursements = $disbursements->filter(function ($d) {
            $fully = (bool) ($d->fully_liquidated ?? false);
            if ($fully) return false;

            $remaining = $d->remaining_to_liquidate; // may be null
            $liquidated = $d->liquidated_amount ?? 0;

            if (is_null($remaining)) return true;               // unknown remaining → allow
            if ($remaining > 0.01) return true;                 // has positive remaining → allow
            // remaining == 0: allow if nothing has been liquidated (initialization missing)
            return ((float)$remaining === 0.0) && ((float)$liquidated === 0.0);
        })->values();

        $disbursements->transform(function ($disbursement) {
            // Compute the amount the UI should consider as "remaining"
            $remainingAmount = $disbursement->remaining_to_liquidate;
            if (is_null($remainingAmount)) {
                $remainingAmount = $disbursement->amount;
            } elseif ((float)$remainingAmount === 0.0 && (float)($disbursement->liquidated_amount ?? 0) === 0.0) {
                // If remaining is zero but nothing was liquidated, treat the whole amount as remaining
                $remainingAmount = $disbursement->amount;
            }

            $fundType = ucfirst($disbursement->aidRequest->fund_type ?? 'other');

            // Determine the month constraint and user-facing period text
            $periodMonth = null; $periodYear = null; $periodText = null; $windowStart = null; $windowEnd = null;
            if ($disbursement->aidRequest->month && $disbursement->aidRequest->year) {
                $periodMonth = $disbursement->aidRequest->month;
                $periodYear = $disbursement->aidRequest->year;
            } elseif (!empty($disbursement->beneficiary_received_at)) {
                $dt = \Carbon\Carbon::parse($disbursement->beneficiary_received_at);
                $periodMonth = $dt->month; $periodYear = $dt->year;
            }
            if ($periodMonth && $periodYear) {
                $start = \Carbon\Carbon::create($periodYear, $periodMonth, 1)->startOfMonth();
                $end = (clone $start)->endOfMonth();
                $periodText = $start->format('F Y');
                $windowStart = $start->format('F j, Y');
                $windowEnd = $end->format('F j, Y');
            }
            
            $receivedDisplay = $disbursement->beneficiary_received_at 
                ? \Carbon\Carbon::parse($disbursement->beneficiary_received_at)->format('M j, Y, h:i A')
                : null;

            $baseText = 'Disbursement #' . $disbursement->id . ' - ₱' . number_format($remainingAmount, 2) . ' remaining (' . $fundType . ')';
            $display = $receivedDisplay ? ($baseText . ' • Received ' . $receivedDisplay) : $baseText;

            return [
                'id' => $disbursement->id,
                'amount' => $remainingAmount, // Use remaining amount, not total amount
                'total_amount' => $disbursement->amount,
                'liquidated_amount' => $disbursement->liquidated_amount ?? 0,
                'remaining_amount' => $remainingAmount,
                'fund_type' => $disbursement->aidRequest->fund_type ?? 'other',
                'received_at' => $disbursement->beneficiary_received_at,
                'received_display' => $receivedDisplay,
                'reference_no' => $disbursement->reference_no,
                'request_month' => $periodMonth,
                'request_year' => $periodYear,
                'request_period' => $periodText,
                'request_window_start' => $windowStart,
                'request_window_end' => $windowEnd,
                'display_text' => $display,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $disbursements,
            'message' => 'Available disbursements retrieved successfully.'
        ]);
    }

    /**
     * Download a receipt file
     */
    public function downloadReceipt($liquidationId, $receiptId)
    {
        $user = Auth::user();
        
        if (!$user || !$user->systemRole) {
            \Log::warning('Receipt download attempted by unauthenticated user', [
                'liquidation_id' => $liquidationId,
                'receipt_id' => $receiptId
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }
        
        $userRole = strtolower($user->systemRole->name ?? '');
        
        \Log::info('Receipt download requested', [
            'user_id' => $user->id,
            'user_role' => $userRole,
            'liquidation_id' => $liquidationId,
            'receipt_id' => $receiptId
        ]);
        
        // Get the receipt with authorization
        $receipt = LiquidationReceipt::with('liquidation.beneficiary')
            ->whereHas('liquidation', function ($q) use ($user, $userRole) {
                // Beneficiaries can only download their own receipts
                if ($userRole === 'beneficiary') {
                    $q->where('beneficiary_id', $user->id);
                }
                // Caseworkers can download receipts from their assigned beneficiaries
                // OR from completed/approved liquidations in their center (for completed liquidations page)
                elseif ($userRole === 'caseworker') {
                    $q->where(function ($subQ) use ($user) {
                        $subQ->whereHas('beneficiary', function ($beneficiaryQuery) use ($user) {
                            $beneficiaryQuery->where('caseworker_id', $user->id);
                        })
                        ->orWhere(function ($completedQ) use ($user) {
                            // Allow access to completed/approved liquidations from their center
                            $completedQ->where('status', 'approved')
                                      ->whereNotNull('director_approved_at')
                                      ->whereHas('beneficiary', function ($beneficiaryQuery) use ($user) {
                                          $beneficiaryQuery->where('financial_aid_id', $user->financial_aid_id);
                                      });
                        });
                    });
                }
                // Finance and directors can download receipts from their assigned center
                elseif (in_array($userRole, ['finance', 'director'])) {
                    $facilityId = $user->financial_aid_id;
                    if ($userRole === 'director' && empty($facilityId)) {
                        $facility = \App\Models\FinancialAid::where('user_id', $user->id)->first();
                        $facilityId = $facility?->id;
                    }
                    $q->whereHas('beneficiary', function ($beneficiaryQuery) use ($facilityId) {
                        $beneficiaryQuery->where('financial_aid_id', $facilityId);
                    });
                }
                // No access for other roles
                else {
                    $q->where('id', -1); // Force no results
                }
            })
            ->where('liquidation_id', $liquidationId)
            ->where('id', $receiptId)
            ->first();

        if (!$receipt) {
            \Log::warning('Receipt not found or access denied for download', [
                'user_id' => $user->id,
                'user_role' => $userRole,
                'liquidation_id' => $liquidationId,
                'receipt_id' => $receiptId
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found or access denied.'
            ], 404);
        }

        // Check if file exists in storage
        if (!$receipt->file_path || !Storage::disk('public')->exists($receipt->file_path)) {
            \Log::error('Receipt file not found in storage', [
                'receipt_id' => $receipt->id,
                'file_path' => $receipt->file_path,
                'storage_path' => $receipt->file_path ? Storage::disk('public')->path($receipt->file_path) : 'N/A',
                'file_exists_on_disk' => $receipt->file_path ? file_exists(Storage::disk('public')->path($receipt->file_path)) : false
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Receipt file not found in storage.',
                'debug' => [
                    'file_path' => $receipt->file_path,
                    'storage_exists' => $receipt->file_path ? Storage::disk('public')->exists($receipt->file_path) : false
                ]
            ], 404);
        }

        try {
            \Log::info('Attempting to download receipt file', [
                'receipt_id' => $receipt->id,
                'file_path' => $receipt->file_path,
                'original_filename' => $receipt->original_filename,
                'mime_type' => $receipt->mime_type
            ]);
            
            $filename = $receipt->original_filename ?: ('receipt-' . $receipt->id . '.' . pathinfo($receipt->file_path, PATHINFO_EXTENSION));
            
            // Try Laravel Storage download method first
            try {
                return Storage::disk('public')->download($receipt->file_path, $filename);
            } catch (\Exception $storageException) {
                \Log::warning('Storage download failed, trying direct file method', [
                    'storage_error' => $storageException->getMessage()
                ]);
                
                // Fallback: Try direct file download
                $fullPath = Storage::disk('public')->path($receipt->file_path);
                
                if (file_exists($fullPath) && is_readable($fullPath)) {
                    \Log::info('Using direct file download method', ['full_path' => $fullPath]);
                    
                    return response()->download($fullPath, $filename, [
                        'Content-Type' => $receipt->mime_type ?: 'application/octet-stream'
                    ]);
                } else {
                    \Log::error('File not accessible via direct path', [
                        'full_path' => $fullPath,
                        'exists' => file_exists($fullPath),
                        'readable' => is_readable($fullPath)
                    ]);
                    
                    // Last resort: Try to serve file contents directly
                    try {
                        $fileContents = Storage::disk('public')->get($receipt->file_path);
                        
                        return response($fileContents, 200, [
                            'Content-Type' => $receipt->mime_type ?: 'application/octet-stream',
                            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                            'Content-Length' => strlen($fileContents)
                        ]);
                    } catch (\Exception $contentException) {
                        \Log::error('Failed to get file contents', [
                            'content_error' => $contentException->getMessage()
                        ]);
                        throw $storageException; // Re-throw original exception
                    }
                }
            }
            
        } catch (\Exception $e) {
            \Log::error('Error downloading receipt file', [
                'receipt_id' => $receipt->id,
                'file_path' => $receipt->file_path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error downloading receipt file: ' . $e->getMessage(),
                'debug' => [
                    'file_path' => $receipt->file_path,
                    'full_path' => Storage::disk('public')->path($receipt->file_path),
                    'storage_exists' => Storage::disk('public')->exists($receipt->file_path),
                    'file_exists' => file_exists(Storage::disk('public')->path($receipt->file_path))
                ]
            ], 500);
        }
    }

    /**
     * Get completed liquidations (fully approved)
     * Only finance and director roles can access this
     */
    public function completedLiquidations(Request $request)
    {
        $user = Auth::user();
        
        // Check if user has permission to view completed liquidations
        $userRole = strtolower($user->systemRole->name ?? '');
        if (!$user || !$user->systemRole || !in_array($userRole, ['finance', 'director', 'caseworker'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only finance, director, and caseworker roles can access completed liquidations.'
            ], 403);
        }

        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $fundType = $request->get('fund_type');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        // Query for liquidations with 'approved' status and director approval
        $query = Liquidation::with([
            'beneficiary:id,firstname,middlename,lastname,email',
            'disbursement.aidRequest:id,fund_type,amount,purpose',
            'receipts:id,liquidation_id,receipt_amount,receipt_date,receipt_number,description,original_filename,file_path,mime_type',
            'directorApprover:id,firstname,lastname'
        ])
        ->where('status', 'approved')
        ->whereNotNull('director_approved_at') // Only director-approved liquidations
        ->whereHas('disbursement', function ($q) {
            $q->where('fully_liquidated', true); // Only fully liquidated disbursements
        });
        
        // Filter based on user role
        if ($userRole === 'caseworker') {
            // Caseworkers can only see liquidations of their assigned beneficiaries
            $query->whereHas('beneficiary', function ($q) use ($user) {
                $q->where('caseworker_id', $user->id);
            });
        } elseif ($userRole === 'finance' || $userRole === 'director') {
            // Finance users are attached to a facility via financial_aid_id.
            // Directors may not have financial_aid_id set; resolve their facility by ownership.
            $facilityId = $user->financial_aid_id;
            if ($userRole === 'director' && empty($facilityId)) {
                $facility = \App\Models\FinancialAid::where('user_id', $user->id)->first();
                $facilityId = $facility?->id;
            }
            if ($facilityId) {
                $query->whereHas('beneficiary', function ($q) use ($facilityId) {
                    $q->where('financial_aid_id', $facilityId);
                });
            } else {
                // If facility cannot be resolved, ensure no records leak
                $query->where('id', -1);
            }
        }

        // Filter by beneficiary search
        if ($search && trim($search) !== '') {
            $query->whereHas('beneficiary', function ($q) use ($search) {
                $q->where(function ($subQ) use ($search) {
$subQ->whereRaw("(firstname || ' ' || COALESCE(middlename, '') || ' ' || lastname) LIKE ?", ["%{$search}%"])
                         ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        // Filter by fund type
        if ($fundType) {
            $query->whereHas('disbursement.aidRequest', function ($q) use ($fundType) {
                $q->where('fund_type', $fundType);
            });
        }

        // Filter by date range (using director approval date)
        if ($dateFrom) {
            $query->where('director_approved_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('director_approved_at', '<=', $dateTo . ' 23:59:59');
        }

        $liquidations = $query->orderByDesc('director_approved_at')->paginate($perPage);

        // Transform the data for frontend display
        $liquidations->getCollection()->transform(function ($liquidation) {
            return [
                'id' => $liquidation->id,
                'beneficiary' => [
                    'id' => $liquidation->beneficiary->id,
                    'name' => trim($liquidation->beneficiary->firstname . ' ' . 
                             ($liquidation->beneficiary->middlename ? $liquidation->beneficiary->middlename . ' ' : '') . 
                             $liquidation->beneficiary->lastname),
                    'email' => $liquidation->beneficiary->email,
                ],
                'fund_amount' => $liquidation->disbursement->aidRequest->amount ?? $liquidation->total_disbursed_amount,
                'fund_type' => ucfirst($liquidation->disbursement->aidRequest->fund_type ?? $liquidation->disbursement_type),
                'purpose' => $liquidation->disbursement->aidRequest->purpose,
                'total_receipt_amount' => $liquidation->total_receipt_amount,
                'liquidation_date' => $liquidation->liquidation_date,
                'final_approval_date' => $liquidation->director_approved_at ? $liquidation->director_approved_at->format('Y-m-d H:i:s') : null,
                'final_approver' => $liquidation->directorApprover ? ($liquidation->directorApprover->firstname . ' ' . $liquidation->directorApprover->lastname) : 'Unknown',
                'receipts_count' => $liquidation->receipts->count(),
                'receipts' => $liquidation->receipts->map(function ($receipt) {
                    return [
                        'id' => $receipt->id,
                        'amount' => $receipt->receipt_amount,
                        'date' => $receipt->receipt_date,
                        'number' => $receipt->receipt_number,
                        'description' => $receipt->description,
                        'filename' => $receipt->original_filename,
                        'original_filename' => $receipt->original_filename, // Ensure both field names are available
                        'has_image' => !empty($receipt->file_path) && !empty($receipt->mime_type),
                        'mime_type' => $receipt->mime_type,
                        'file_path' => $receipt->file_path, // Include file path info for debugging
                        'file_size' => $receipt->file_size,
                        'is_image' => $receipt->mime_type && str_starts_with($receipt->mime_type, 'image/'),
                        'is_pdf' => $receipt->mime_type === 'application/pdf',
                    ];
                }),
                'status' => 'Fully Approved',
                'description' => $liquidation->description,
                'created_at' => $liquidation->created_at->format('Y-m-d H:i:s'),
            ];
        });

        // Get summary statistics
        $summaryQuery = Liquidation::where('status', 'approved')
            ->whereNotNull('director_approved_at')
            ->whereHas('disbursement', function ($q) {
                $q->where('fully_liquidated', true); // Only fully liquidated disbursements
            });
            
        // Apply center filtering for summary statistics
        if ($userRole === 'caseworker') {
            // Caseworkers see only their assigned beneficiaries' statistics
            $summaryQuery->whereHas('beneficiary', function ($q) use ($user) {
                $q->where('caseworker_id', $user->id);
            });
        } elseif ($userRole === 'finance' || $userRole === 'director') {
            // Finance and directors see statistics from their assigned center
            $facilityId = $user->financial_aid_id;
            if ($userRole === 'director' && empty($facilityId)) {
                $facility = \App\Models\FinancialAid::where('user_id', $user->id)->first();
                $facilityId = $facility?->id;
            }
            if ($facilityId) {
                $summaryQuery->whereHas('beneficiary', function ($q) use ($facilityId) {
                    $q->where('financial_aid_id', $facilityId);
                });
            } else {
                $summaryQuery->where('id', -1);
            }
        }
        
        // Apply search filter for summary statistics too
        if ($search && trim($search) !== '') {
            $summaryQuery->whereHas('beneficiary', function ($q) use ($search) {
                $q->where(function ($subQ) use ($search) {
$subQ->whereRaw("(firstname || ' ' || COALESCE(middlename, '') || ' ' || lastname) LIKE ?", ["%{$search}%"])
                         ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }
        
        // Apply fund type filter for summary statistics too
        if ($fundType) {
            $summaryQuery->whereHas('disbursement.aidRequest', function ($q) use ($fundType) {
                $q->where('fund_type', $fundType);
            });
        }
        
        // Apply date range filter for summary statistics too
        if ($dateFrom) {
            $summaryQuery->where('director_approved_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $summaryQuery->where('director_approved_at', '<=', $dateTo . ' 23:59:59');
        }
        
        $totalCompleted = $summaryQuery->count();
        $totalAmount = $summaryQuery->sum('total_disbursed_amount');

        $message = 'Completed liquidations retrieved successfully.';
        if ($liquidations->count() === 0) {
            if ($search || $fundType || $dateFrom || $dateTo) {
                $message = 'No completed liquidations found matching your search criteria.';
            } else {
                $message = 'No completed liquidations found.';
            }
        }

        return response()->json([
            'success' => true,
            'data' => $liquidations,
            'summary' => [
                'total_completed' => $totalCompleted,
                'total_amount' => $totalAmount,
                'current_page_count' => $liquidations->count(),
            ],
            'message' => $message
        ]);
    }

    /**
     * Download liquidation summary as PDF
     * Only authorized users can download summaries
     */
    public function downloadSummary($liquidationId)
    {
        $user = Auth::user();
        
        // Check if user has permission to download liquidation summaries
        $userRole = strtolower($user->systemRole->name ?? '');
        if (!$user || !$user->systemRole || !in_array($userRole, ['finance', 'director', 'caseworker'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only finance, director, and caseworker roles can download liquidation summaries.'
            ], 403);
        }

        // Get the liquidation with proper authorization
        $query = Liquidation::with([
            'beneficiary:id,firstname,middlename,lastname,email',
            'receipts' => function ($query) {
                $query->orderBy('receipt_date');
            },
            'disbursement.aidRequest:id,fund_type,amount,purpose',
            'caseworkerApprover:id,firstname,lastname',
            'financeApprover:id,firstname,lastname',
            'directorApprover:id,firstname,lastname'
        ])
        ->where('id', $liquidationId)
        ->where('status', 'approved')
        ->whereNotNull('director_approved_at'); // Only director-approved liquidations
        
        // Apply center-based filtering
        if ($userRole === 'caseworker') {
            // Caseworkers can only download summaries for their assigned beneficiaries
            $query->whereHas('beneficiary', function ($q) use ($user) {
                $q->where('caseworker_id', $user->id);
            });
        } elseif ($userRole === 'finance' || $userRole === 'director') {
            // Finance and directors can download summaries from their assigned center
            $facilityId = $user->financial_aid_id;
            if ($userRole === 'director' && empty($facilityId)) {
                $facility = \App\Models\FinancialAid::where('user_id', $user->id)->first();
                $facilityId = $facility?->id;
            }
            $query->whereHas('beneficiary', function ($q) use ($facilityId) {
                $q->where('financial_aid_id', $facilityId);
            });
        }

        $liquidation = $query->first();

        if (!$liquidation) {
            return response()->json([
                'success' => false,
                'message' => 'Liquidation not found or access denied. Only fully approved liquidations can be downloaded.'
            ], 404);
        }

        try {
            // Generate PDF using the service
            $summaryService = new LiquidationSummaryService();
            $pdf = $summaryService->generateSummaryPdf($liquidation);
            $filename = $summaryService->getSuggestedFilename($liquidation);

            \Log::info('Liquidation summary PDF generated', [
                'user_id' => $user->id,
                'user_role' => $userRole,
                'liquidation_id' => $liquidation->id,
                'beneficiary_name' => $liquidation->beneficiary->firstname . ' ' . $liquidation->beneficiary->lastname,
                'filename' => $filename
            ]);

            return $pdf->download($filename);

        } catch (\Exception $e) {
            \Log::error('Error generating liquidation summary PDF', [
                'liquidation_id' => $liquidation->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating liquidation summary: ' . $e->getMessage()
            ], 500);
        }
    }
}
