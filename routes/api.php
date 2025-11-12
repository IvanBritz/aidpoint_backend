<?php

use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\UserSubscriptionController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\FundAllocationController;
use App\Http\Controllers\FinancialAidController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\CaseworkerController;
use App\Http\Controllers\EmployeeController;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    // Include role and caseworker for beneficiary dashboard
    return $request->user()->load(['systemRole','caseworker']);
});

// Public read-only subscription plans
Route::get('public/subscription-plans', function () {
    $plans = SubscriptionPlan::where('archived', false)->orderBy('plan_name')->get();
    return response()->json([
        'success' => true,
        'data' => $plans,
        'message' => 'Subscription plans retrieved successfully.'
    ]);
});

// Simple alias to match generic docs/UI expectations
Route::get('plans', function () {
    $plans = SubscriptionPlan::where('archived', false)->orderBy('plan_name')->get();
    return response()->json(['success' => true, 'data' => $plans]);
});

Route::get('public/subscription-plans/{subscriptionPlan}', function (SubscriptionPlan $subscriptionPlan) {
    return response()->json([
        'success' => true,
        'data' => $subscriptionPlan,
        'message' => 'Subscription plan retrieved successfully.'
    ]);
});


// Financial Aid Routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('my-facilities', [FinancialAidController::class, 'myFacilities']);
    Route::apiResource('financial-aid', FinancialAidController::class);
    Route::patch('financial-aid/{id}/status', [FinancialAidController::class, 'updateStatus']);
    
    // Employees (facility-scoped)
    Route::apiResource('employees', EmployeeController::class)->only(['index','store','update','destroy']);
    Route::post('employees/{id}/reset-password', [EmployeeController::class, 'resetPassword']);
    
    // Subscription Plan Routes (Admin only)
    Route::apiResource('subscription-plans', SubscriptionPlanController::class, [
        'parameters' => ['subscription-plans' => 'subscriptionPlan']
    ]);

    // Admin Dashboard data
    Route::get('admin/dashboard', [AdminDashboardController::class, 'dashboard']);
    
    // User Subscription Routes
    Route::get('my-subscriptions', [UserSubscriptionController::class, 'mySubscriptions']);
    Route::get('subscription-status', [UserSubscriptionController::class, 'subscriptionStatus']);
    Route::post('subscribe', [UserSubscriptionController::class, 'subscribe']);
    Route::post('subscribe/free-trial', [UserSubscriptionController::class, 'availFreeTrial']);
    Route::get('free-trial-eligibility/{planId}', [UserSubscriptionController::class, 'freeTrialEligibility']);
    Route::post('manual-subscription-activate', [UserSubscriptionController::class, 'manualActivateSubscription']);
    Route::delete('cancel-pending-subscription', [UserSubscriptionController::class, 'cancelPendingSubscription']);
    Route::get('subscription-transactions', [UserSubscriptionController::class, 'transactionHistory']);
    
    // Admin: all subscription transactions
    Route::get('admin/subscription-transactions', [UserSubscriptionController::class, 'adminTransactions']);

    // Receipt download (owner or admin)
    Route::get('subscriptions/{id}/receipt', [UserSubscriptionController::class, 'receipt']);

    // PayMongo subscription checkout (GCash/Maya)
    Route::post('payments/paymongo/checkout', [\App\Http\Controllers\Api\PayMongoController::class, 'createCheckout']);

    // PayMongo Payment Intent flow (cards or saved methods)
    Route::post('payments/paymongo/intent', [\App\Http\Controllers\Api\PayMongoController::class, 'createPaymentIntent']);
    Route::post('payments/paymongo/confirm', [\App\Http\Controllers\Api\PayMongoController::class, 'confirmPaymentIntent']);
    Route::post('payments/paymongo/intent/verify', [\App\Http\Controllers\Api\PayMongoController::class, 'verifyIntent']);
    Route::post('payments/paymongo/checkout/verify', [\App\Http\Controllers\Api\PayMongoController::class, 'verifyCheckout']);
    Route::get('payments/paymongo/checkout/{checkoutId}/debug', [\App\Http\Controllers\Api\PayMongoController::class, 'debugCheckout']);
    // Alias to match requirement without breaking existing /subscribe behavior elsewhere
    Route::post('subscribe-intent', [\App\Http\Controllers\Api\PayMongoController::class, 'createPaymentIntent']);
    
    // Beneficiary Routes (for financial facilities)
    Route::apiResource('beneficiaries', BeneficiaryController::class);
    // Caseworker assigned beneficiaries
    Route::get('my-assigned-beneficiaries', [BeneficiaryController::class, 'myAssigned']);
    Route::post('beneficiaries/{id}/reset-password', [BeneficiaryController::class, 'resetPassword']);
    // Director: request exit letter upload
    Route::post('beneficiaries/{id}/request-exit-letter', [BeneficiaryController::class, 'requestExitLetter']);

    // Beneficiary Document Submission Routes
    Route::post('beneficiary/document-submissions', [\App\Http\Controllers\BeneficiaryDocumentSubmissionController::class, 'store']);
    Route::get('beneficiary/my-document-submission', [\App\Http\Controllers\BeneficiaryDocumentSubmissionController::class, 'mySubmission']);

    // Caseworker review of submissions
    Route::get('beneficiary-document-submissions/pending', [\App\Http\Controllers\BeneficiaryDocumentSubmissionController::class, 'pendingForCaseworker']);
    Route::post('beneficiary-document-submissions/{id}/review', [\App\Http\Controllers\BeneficiaryDocumentSubmissionController::class, 'review']);
    
    // Caseworker Routes
    Route::get('caseworkers', [CaseworkerController::class, 'index']);
    Route::get('caseworkers/dropdown', [CaseworkerController::class, 'forDropdown']);
    Route::post('caseworkers/assign', [CaseworkerController::class, 'assignBeneficiary']);

    // Document serving route with authentication
    Route::get('documents/{path}', [FinancialAidController::class, 'serveDocument'])->where('path', '.*');
    
    // Fund Allocation Routes
    Route::get('funds/dashboard', [FundAllocationController::class, 'dashboard']); // per-user (finance)
    Route::get('funds/facility-dashboard', [FundAllocationController::class, 'facilityDashboard']); // facility-wide (director)
    // Combined report: download + share via email
    Route::get('funds/combined-report', [FundAllocationController::class, 'combinedReport']);
    Route::post('funds/combined-report/share', [FundAllocationController::class, 'shareCombinedReport']);
    Route::apiResource('fund-allocations', FundAllocationController::class);

    // Aid Request Routes
    Route::get('beneficiary/aid-requests', [\App\Http\Controllers\AidRequestController::class, 'myRequests']);
    Route::post('beneficiary/aid-requests', [\App\Http\Controllers\AidRequestController::class, 'store']);
    
    // COLA Calculation Routes
    Route::post('beneficiary/cola-preview', [\App\Http\Controllers\AidRequestController::class, 'previewColaCalculation']);
    Route::post('calculate-cola-amount', [\App\Http\Controllers\AttendanceController::class, 'calculateColaAmount']);

    // Caseworker stage
    Route::get('aid-requests/pending', [\App\Http\Controllers\AidRequestController::class, 'pendingForCaseworker']);
    Route::post('aid-requests/{id}/review', [\App\Http\Controllers\AidRequestController::class, 'review']);

    // Finance stage
    Route::get('aid-requests/finance/pending', [\App\Http\Controllers\AidRequestController::class, 'pendingForFinance']);
    Route::post('aid-requests/{id}/finance-review', [\App\Http\Controllers\AidRequestController::class, 'financeReview']);

    // Director stage
    Route::get('aid-requests/director/pending', [\App\Http\Controllers\AidRequestController::class, 'pendingForDirector']);
    Route::post('aid-requests/{id}/director-review', [\App\Http\Controllers\AidRequestController::class, 'directorReview']);

    // Disbursement workflow (Finance → Caseworker → Beneficiary)
    Route::get('disbursements/finance/ready', [\App\Http\Controllers\DisbursementController::class, 'readyForFinance']);
    Route::get('disbursements/caseworker', [\App\Http\Controllers\DisbursementController::class, 'forCaseworker']);
    Route::post('aid-requests/{id}/disburse', [\App\Http\Controllers\DisbursementController::class, 'financeDisburse']);
    Route::post('disbursements/{id}/caseworker-receive', [\App\Http\Controllers\DisbursementController::class, 'caseworkerReceive']);
    Route::post('disbursements/{id}/caseworker-disburse', [\App\Http\Controllers\DisbursementController::class, 'caseworkerDisburse']);
    Route::post('disbursements/{id}/beneficiary-receive', [\App\Http\Controllers\DisbursementController::class, 'beneficiaryReceive']);
    Route::get('disbursements/received', [\App\Http\Controllers\DisbursementController::class, 'receivedByBeneficiaries']);
    Route::get('my-disbursements', [\App\Http\Controllers\DisbursementController::class, 'myDisbursements']);
    
    // Authenticated user password update
    Route::put('user/password', [\App\Http\Controllers\Auth\UserPasswordController::class, 'update']);
    
    // Lifecycle: expire now and suspend access (client trigger when remaining hits 0)
    Route::post('subscriptions/expire-now', [\App\Http\Controllers\Api\AccessCheckController::class, 'checkExpirationAndSuspend']);

    // Enhanced Notification Routes
    Route::get('notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount']);
    Route::get('notifications/recent', [\App\Http\Controllers\NotificationController::class, 'recent']);
    Route::get('notifications/statistics', [\App\Http\Controllers\NotificationController::class, 'statistics']);
    Route::post('notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::post('notifications/mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy']);
    
    // Audit Log Routes (Finance, Director, Admin only)
    Route::get('audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index']);
    Route::get('audit-logs/statistics', [\App\Http\Controllers\AuditLogController::class, 'statistics']);
    Route::get('audit-logs/filter-options', [\App\Http\Controllers\AuditLogController::class, 'filterOptions']);
    
    // Attendance Management Routes (Caseworker)
    Route::get('attendance', [\App\Http\Controllers\AttendanceController::class, 'index']);
    Route::get('attendance/beneficiaries', [\App\Http\Controllers\AttendanceController::class, 'getAssignedBeneficiaries']);
    Route::get('attendance/beneficiaries/{beneficiaryId}/monthly', [\App\Http\Controllers\AttendanceController::class, 'getBeneficiaryMonthlyAttendance']);
    Route::post('attendance/record', [\App\Http\Controllers\AttendanceController::class, 'recordAttendance']);
    Route::put('attendance/{id}', [\App\Http\Controllers\AttendanceController::class, 'updateAttendance']);
    
    // Liquidation Routes
    Route::get('beneficiary/liquidations', [\App\Http\Controllers\LiquidationController::class, 'index']);
    Route::post('beneficiary/liquidations', [\App\Http\Controllers\LiquidationController::class, 'store']);
    Route::get('beneficiary/disbursements/available', [\App\Http\Controllers\LiquidationController::class, 'availableDisbursements']);
    
    // Caseworker liquidation review (legacy - kept for backward compatibility)
    Route::get('caseworker/liquidations', [\App\Http\Controllers\LiquidationController::class, 'forCaseworker']);
    Route::post('liquidations/{id}/review', [\App\Http\Controllers\LiquidationController::class, 'review']);
    
    // Multi-level Liquidation Approval Workflow
    Route::get('liquidations/pending-approvals', [\App\Http\Controllers\LiquidationApprovalController::class, 'getPendingApprovals']);
    Route::get('liquidations/{id}/for-approval', [\App\Http\Controllers\LiquidationApprovalController::class, 'getLiquidationForApproval']);
    Route::get('liquidations/{id}/approval-history', [\App\Http\Controllers\LiquidationApprovalController::class, 'getApprovalHistory']);
    
    // Beneficiary submission for approval
    Route::post('liquidations/{id}/submit-for-approval', [\App\Http\Controllers\LiquidationApprovalController::class, 'submitForApproval']);
    
    // Caseworker approval/rejection
    Route::post('liquidations/{id}/caseworker-approve', [\App\Http\Controllers\LiquidationApprovalController::class, 'approveByCaseworker']);
    Route::post('liquidations/{id}/caseworker-reject', [\App\Http\Controllers\LiquidationApprovalController::class, 'rejectByCaseworker']);
    
    // Finance team approval/rejection
    Route::post('liquidations/{id}/finance-approve', [\App\Http\Controllers\LiquidationApprovalController::class, 'approveByFinance']);
    Route::post('liquidations/{id}/finance-reject', [\App\Http\Controllers\LiquidationApprovalController::class, 'rejectByFinance']);
    
    // Project director approval/rejection
    Route::post('liquidations/{id}/director-approve', [\App\Http\Controllers\LiquidationApprovalController::class, 'approveByDirector']);
    Route::post('liquidations/{id}/director-reject', [\App\Http\Controllers\LiquidationApprovalController::class, 'rejectByDirector']);
    
    // Receipt downloads
    Route::get('liquidations/{liquidationId}/receipts/{receiptId}/download', [\App\Http\Controllers\LiquidationController::class, 'downloadReceipt']);
    
    // Receipt image viewing (inline display)
    Route::get('liquidations/{liquidationId}/receipts/{receiptId}/view', [\App\Http\Controllers\LiquidationImageController::class, 'viewReceiptImage']);
    
    // Generate signed URL for receipt image
    Route::get('liquidations/{liquidationId}/receipts/{receiptId}/signed-url', [\App\Http\Controllers\LiquidationImageController::class, 'generateSignedUrl']);
    
    // Completed liquidations (fully approved cases)
    Route::get('liquidations/completed', [\App\Http\Controllers\LiquidationController::class, 'completedLiquidations']);
    
    // Download liquidation summary PDF
    Route::get('liquidations/{liquidationId}/download-summary', [\App\Http\Controllers\LiquidationController::class, 'downloadSummary']);
    
    
    // Debug endpoint for disbursements
    Route::get('debug/disbursements', function() {
        $user = auth()->user();
        if (!$user) return response()->json(['error' => 'Not authenticated']);
        
        $disbursements = \App\Models\Disbursement::with(['aidRequest'])->get();
        return response()->json([
            'user' => $user->only(['id', 'firstname', 'lastname', 'systemrole_id']),
            'user_role' => $user->systemRole?->name,
            'all_disbursements' => $disbursements->toArray()
        ]);
    });
    
    // Analytics Routes (Admin/Director only)
    Route::get('analytics/dashboard', [\App\Http\Controllers\AnalyticsController::class, 'dashboard']);
    
    // Director-specific API endpoints
    Route::get('director/dashboard-data', [\App\Http\Controllers\DirectorController::class, 'dashboardData']); // Optimized: all data in one request
    Route::get('director/facility-overview', [\App\Http\Controllers\DirectorController::class, 'facilityOverview']);
    Route::get('director/pending-approvals', [\App\Http\Controllers\DirectorController::class, 'pendingApprovals']);
    Route::get('director/staff-performance', [\App\Http\Controllers\DirectorController::class, 'staffPerformance']);
    Route::get('director/facility-analytics', [\App\Http\Controllers\DirectorController::class, 'facilityAnalytics']);
    
    // Receipt debugging route (authenticated)
    Route::get('debug/liquidations/{liquidationId}/receipts/{receiptId}/info', [\App\Http\Controllers\LiquidationImageController::class, 'debugReceipt']);
    
    // Feedback/Review Routes
    Route::get('feedback', [\App\Http\Controllers\FeedbackController::class, 'index']); // Admin: all feedback
    Route::get('my-feedback', [\App\Http\Controllers\FeedbackController::class, 'myFeedback']); // User: own feedback
    Route::post('feedback', [\App\Http\Controllers\FeedbackController::class, 'store']); // Submit feedback
    Route::put('feedback/{id}', [\App\Http\Controllers\FeedbackController::class, 'update']); // Admin: update/respond
    Route::delete('feedback/{id}', [\App\Http\Controllers\FeedbackController::class, 'destroy']); // Admin: delete
    Route::get('feedback/statistics', [\App\Http\Controllers\FeedbackController::class, 'statistics']); // Admin: stats
    
    // Enhanced receipt debug endpoint for caseworkers
    Route::get('debug/liquidations/{liquidationId}/receipts/{receiptId}/access-check', function($liquidationId, $receiptId) {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        
        $userRole = strtolower($user->systemRole->name ?? '');
        
        $receipt = \App\Models\LiquidationReceipt::with('liquidation.beneficiary')
                                ->where('liquidation_id', $liquidationId)
                                ->where('id', $receiptId)
                                ->first();
        
        if (!$receipt) {
            return response()->json(['error' => 'Receipt not found'], 404);
        }
        
        $liquidation = $receipt->liquidation;
        
        // Check various access conditions
        $accessChecks = [
            'user_role' => $userRole,
            'user_id' => $user->id,
            'receipt_exists' => true,
            'liquidation_id' => $liquidation->id,
            'liquidation_status' => $liquidation->status,
            'beneficiary_id' => $liquidation->beneficiary_id,
            'beneficiary_caseworker_id' => $liquidation->beneficiary->caseworker_id ?? null,
            'is_assigned_caseworker' => $liquidation->beneficiary->caseworker_id === $user->id,
            'is_approved_liquidation' => $liquidation->status === 'approved' && $liquidation->director_approved_at !== null,
            'director_approved_at' => $liquidation->director_approved_at,
            'file_path' => $receipt->file_path,
            'file_exists' => \Illuminate\Support\Facades\Storage::disk('public')->exists($receipt->file_path),
            'mime_type' => $receipt->mime_type,
        ];
        
        return response()->json([
            'access_analysis' => $accessChecks,
            'should_have_access' => (
                $userRole === 'caseworker' && (
                    $liquidation->beneficiary->caseworker_id === $user->id ||
                    ($liquidation->status === 'approved' && $liquidation->director_approved_at !== null)
                )
            ) || in_array($userRole, ['finance', 'director']),
            'receipt_data' => [
                'id' => $receipt->id,
                'original_filename' => $receipt->original_filename,
                'mime_type' => $receipt->mime_type,
                'file_size' => $receipt->file_size,
                'file_path' => $receipt->file_path
            ]
        ]);
    });
    
    // Simple test route to check if receipt image serving works at all
    Route::get('test/liquidations/{liquidationId}/receipts/{receiptId}/view', function($liquidationId, $receiptId) {
        $user = auth()->user();
        
        // Get receipt without any authorization checks (for testing)
        $receipt = \App\Models\LiquidationReceipt::where('liquidation_id', $liquidationId)
                                              ->where('id', $receiptId)
                                              ->first();
        
        if (!$receipt) {
            return response()->json(['error' => 'Receipt not found'], 404);
        }
        
        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($receipt->file_path)) {
            return response()->json([
                'error' => 'File not found in storage',
                'path' => $receipt->file_path,
                'full_path' => \Illuminate\Support\Facades\Storage::disk('public')->path($receipt->file_path)
            ], 404);
        }
        
        try {
            $file = \Illuminate\Support\Facades\Storage::disk('public')->get($receipt->file_path);
            $mimeType = $receipt->mime_type ?: \Illuminate\Support\Facades\Storage::disk('public')->mimeType($receipt->file_path);
            
            return response($file)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline; filename="' . $receipt->original_filename . '"');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to serve file: ' . $e->getMessage()], 500);
        }
    });
    
    // Test endpoint for file download debugging
    Route::get('debug/file-download-test/{liquidationId}/{receiptId}', function($liquidationId, $receiptId) {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        
        $receipt = \App\Models\LiquidationReceipt::with('liquidation.beneficiary')
                    ->where('liquidation_id', $liquidationId)
                    ->where('id', $receiptId)
                    ->first();
        
        if (!$receipt) {
            return response()->json(['error' => 'Receipt not found'], 404);
        }
        
        $storagePath = \Illuminate\Support\Facades\Storage::disk('public')->path($receipt->file_path);
        
        return response()->json([
            'message' => 'File download debug information',
            'receipt_info' => [
                'id' => $receipt->id,
                'file_path' => $receipt->file_path,
                'original_filename' => $receipt->original_filename,
                'mime_type' => $receipt->mime_type,
                'file_size' => $receipt->file_size,
            ],
            'storage_info' => [
                'storage_path' => $storagePath,
                'file_exists_in_storage' => \Illuminate\Support\Facades\Storage::disk('public')->exists($receipt->file_path),
                'file_exists_on_disk' => file_exists($storagePath),
                'file_readable' => is_readable($storagePath),
                'storage_disk_root' => \Illuminate\Support\Facades\Storage::disk('public')->path(''),
            ],
            'download_urls' => [
                'api_download' => url("/api/liquidations/{$liquidationId}/receipts/{$receiptId}/download"),
                'direct_storage' => \Illuminate\Support\Facades\Storage::disk('public')->url($receipt->file_path),
            ]
        ]);
    });
    
    // Alternative download endpoint that uses direct file serving
    Route::get('liquidations/{liquidationId}/receipts/{receiptId}/download-direct', function($liquidationId, $receiptId) {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        
        $receipt = \App\Models\LiquidationReceipt::where('liquidation_id', $liquidationId)
                    ->where('id', $receiptId)
                    ->first();
        
        if (!$receipt) {
            return response()->json(['error' => 'Receipt not found'], 404);
        }
        
        $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($receipt->file_path);
        
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found on disk'], 404);
        }
        
        return response()->download($filePath, $receipt->original_filename);
    });
    
    // Test endpoint to verify receipt image system
    Route::get('test/receipt-image-system', function() {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        
        // Get a sample receipt for testing
        $receipt = \App\Models\LiquidationReceipt::with('liquidation.beneficiary')
                    ->whereHas('liquidation', function($q) {
                        $q->where('status', 'approved')
                          ->whereNotNull('director_approved_at');
                    })
                    ->first();
        
        if (!$receipt) {
            return response()->json(['message' => 'No completed liquidation receipts found for testing']);
        }
        
        $liquidation = $receipt->liquidation;
        
        return response()->json([
            'message' => 'Receipt image system test',
            'user_info' => [
                'id' => $user->id,
                'role' => $user->systemRole->name ?? 'No role',
            ],
            'sample_receipt' => [
                'id' => $receipt->id,
                'liquidation_id' => $liquidation->id,
                'filename' => $receipt->original_filename,
                'mime_type' => $receipt->mime_type,
                'file_exists' => \Illuminate\Support\Facades\Storage::disk('public')->exists($receipt->file_path),
                'view_url' => url("/api/liquidations/{$liquidation->id}/receipts/{$receipt->id}/view"),
                'download_url' => url("/api/liquidations/{$liquidation->id}/receipts/{$receipt->id}/download"),
                'debug_url' => url("/api/debug/liquidations/{$liquidation->id}/receipts/{$receipt->id}/access-check"),
            ],
            'liquidation_info' => [
                'id' => $liquidation->id,
                'status' => $liquidation->status,
                'beneficiary_id' => $liquidation->beneficiary_id,
                'caseworker_id' => $liquidation->beneficiary->caseworker_id ?? null,
                'director_approved' => $liquidation->director_approved_at !== null,
            ]
        ]);
    });

});

// Debug endpoints (non-authenticated for testing)
Route::get('debug/liquidations/{liquidationId}/receipts/{receiptId}', function($liquidationId, $receiptId) {
    $receipt = \App\Models\LiquidationReceipt::where('liquidation_id', $liquidationId)
                                ->where('id', $receiptId)
                                ->first();
    
    if (!$receipt) {
        return response()->json([
            'found' => false,
            'message' => 'Receipt not found'
        ]);
    }
    
    return response()->json([
        'found' => true,
        'receipt' => $receipt->toArray(),
        'file_exists_in_storage' => Storage::disk('public')->exists($receipt->file_path),
        'full_storage_path' => Storage::disk('public')->path($receipt->file_path),
        'file_exists_on_disk' => file_exists(Storage::disk('public')->path($receipt->file_path)),
    ]);
});

Route::get('debug/test-image', function() {
    $filePath = 'liquidation-receipts/21/7/1759734328_0.png';
    if (!Storage::disk('public')->exists($filePath)) {
        return response()->json(['error' => 'File not found', 'path' => $filePath]);
    }
    $file = Storage::disk('public')->get($filePath);
    return response($file)->header('Content-Type', 'image/png');
});

// Public route for signed receipt image serving (no auth required)
Route::get('public/receipts/{liquidationId}/{receiptId}/image', [\App\Http\Controllers\LiquidationImageController::class, 'viewReceiptImagePublic'])
     ->name('receipt.image.public')
     ->middleware('signed');

// PayMongo webhook receiver (configure this URL in Dashboard -> Webhooks)
Route::post('webhooks/paymongo', [\App\Http\Controllers\Api\PayMongoController::class, 'webhook']);
// Alternate short endpoint: /api/webhook
Route::post('webhook', [\App\Http\Controllers\Api\PayMongoController::class, 'webhook']);
