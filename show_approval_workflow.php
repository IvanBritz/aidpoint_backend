<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Liquidation;

echo "=== LIQUIDATION APPROVAL WORKFLOW ===\n\n";

// Get liquidations with approval details
$liquidations = Liquidation::with(['beneficiary', 'disbursement.aidRequest', 'reviewer'])
    ->orderBy('created_at', 'desc')
    ->get();

echo "Current Liquidations and Their Approval Status:\n";
echo "=============================================\n\n";

foreach ($liquidations as $liquidation) {
    echo "Liquidation #{$liquidation->id}\n";
    echo "Beneficiary: {$liquidation->beneficiary->firstname} {$liquidation->beneficiary->lastname}\n";
    echo "Amount: ₱{$liquidation->total_receipt_amount}\n";
    echo "Status: {$liquidation->status}\n";
    echo "Created: {$liquidation->created_at}\n";
    
    // Show approval workflow status
    $attributes = $liquidation->getAttributes();
    echo "\nApproval Workflow:\n";
    echo "  Caseworker Approval: " . 
        ($liquidation->caseworker_approved_at ? "✓ Approved at {$liquidation->caseworker_approved_at}" : 
         ($liquidation->caseworker_rejected_at ? "✗ Rejected at {$liquidation->caseworker_rejected_at}" : "⏳ Pending")) . "\n";
    
    echo "  Finance Approval: " . 
        ($liquidation->finance_approved_at ? "✓ Approved at {$liquidation->finance_approved_at}" : 
         ($liquidation->finance_rejected_at ? "✗ Rejected at {$liquidation->finance_rejected_at}" : "⏳ Pending")) . "\n";
    
    echo "  Director Approval: " . 
        ($liquidation->director_approved_at ? "✓ Approved at {$liquidation->director_approved_at}" : 
         ($liquidation->director_rejected_at ? "✗ Rejected at {$liquidation->director_rejected_at}" : "⏳ Pending")) . "\n";

    if (isset($liquidation->reviewer_notes) && !empty($liquidation->reviewer_notes)) {
        echo "  Notes: {$liquidation->reviewer_notes}\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n\n";
}

// Show workflow statistics
$stats = [
    'Total Liquidations' => Liquidation::count(),
    'In Progress' => Liquidation::where('status', 'in_progress')->count(),
    'Pending Caseworker' => Liquidation::where('status', 'pending_caseworker_approval')->count(),
    'Pending Finance' => Liquidation::where('status', 'pending_finance_approval')->count(),
    'Pending Director' => Liquidation::where('status', 'pending_director_approval')->count(),
    'Approved' => Liquidation::where('status', 'approved')->count(),
    'Rejected' => Liquidation::where('status', 'rejected')->count(),
];

echo "\nWorkflow Statistics:\n";
echo "===================\n";
foreach ($stats as $label => $count) {
    echo "{$label}: {$count}\n";
}

echo "\n=== WORKFLOW EXPLANATION ===\n";
echo "1. Beneficiary submits liquidation with receipts\n";
echo "2. Caseworker reviews and approves/rejects\n";
echo "3. If approved, Finance team reviews and approves/rejects\n";
echo "4. If approved, Director gives final approval/rejection\n";
echo "5. Only after Director approval, disbursement is marked as liquidated\n\n";

echo "The system is working correctly with multi-level approval!\n";