<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Disbursement;
use App\Models\User;
use App\Models\Liquidation;

echo "=== LIQUIDATION SYSTEM OVERVIEW ===\n\n";

// Get beneficiaries who have disbursements needing liquidation
$beneficiaries = User::whereHas('systemRole', function($q) {
        $q->where('name', 'beneficiary');
    })
    ->whereHas('aidRequests.disbursements', function($q) {
        $q->where('status', 'beneficiary_received');
    })
    ->with(['aidRequests.disbursements' => function($q) {
        $q->where('status', 'beneficiary_received');
    }])
    ->get();

echo "Beneficiaries with disbursements needing liquidation:\n";
echo "=====================================================\n";

foreach ($beneficiaries as $beneficiary) {
    echo "Beneficiary: {$beneficiary->firstname} {$beneficiary->lastname} (ID: {$beneficiary->id})\n";
    echo "Email: {$beneficiary->email}\n";
    
    foreach ($beneficiary->aidRequests as $aidRequest) {
        foreach ($aidRequest->disbursements as $disbursement) {
            echo "  - Disbursement #{$disbursement->id}: ₱{$disbursement->amount} ({$aidRequest->fund_type})\n";
            echo "    Status: {$disbursement->status}\n";
            echo "    Received: {$disbursement->beneficiary_received_at}\n";
            echo "    Liquidated: ₱" . ($disbursement->liquidated_amount ?? '0.00') . "\n";
            echo "    Remaining: ₱" . ($disbursement->remaining_to_liquidate ?? $disbursement->amount) . "\n";
        }
    }
    echo "\n";
}

// Show existing liquidations
$existingLiquidations = Liquidation::with(['beneficiary', 'disbursement.aidRequest'])
    ->orderBy('created_at', 'desc')
    ->get();

echo "\nExisting Liquidations:\n";
echo "====================\n";
if ($existingLiquidations->count() > 0) {
    foreach ($existingLiquidations as $liquidation) {
        echo "Liquidation #{$liquidation->id} - {$liquidation->beneficiary->firstname} {$liquidation->beneficiary->lastname}\n";
        echo "  Amount: ₱{$liquidation->total_receipt_amount}\n";
        echo "  Status: {$liquidation->status}\n";
        echo "  Created: {$liquidation->created_at}\n";
        echo "  Disbursement: #{$liquidation->disbursement_id} (₱{$liquidation->disbursement->amount})\n\n";
    }
} else {
    echo "No liquidations found - Ready for testing!\n\n";
}

echo "=== NEXT STEPS ===\n";
echo "1. Open your browser and go to: http://localhost:3000\n";
echo "2. Login as a beneficiary (use one of the emails above)\n";
echo "3. Navigate to the Liquidation page\n";
echo "4. Submit receipts for the available disbursements\n";
echo "5. The liquidation will go through the approval workflow\n\n";

// Show sample login credentials
$sampleBeneficiary = $beneficiaries->first();
if ($sampleBeneficiary) {
    echo "Sample Login:\n";
    echo "Email: {$sampleBeneficiary->email}\n";
    echo "Password: [Check your database or use default password]\n\n";
}

echo "System is ready for liquidation testing!\n";