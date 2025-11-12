<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;
use App\Models\AidRequest;
use App\Models\Liquidation;

echo "=== Testing Aid Requests & Liquidations ===\n\n";

// Check all aid requests
echo "--- All Aid Requests ---\n";
$allAidRequests = AidRequest::with('beneficiary.financialAid')->get();
foreach ($allAidRequests as $request) {
    $facilityId = $request->beneficiary->financial_aid_id ?? 'N/A';
    $facilityName = $request->beneficiary->financialAid->center_name ?? 'Unknown';
    echo "ID: {$request->id}, Status: {$request->status}, Director Status: {$request->director_approval_status}, Beneficiary Facility: {$facilityId} ({$facilityName})\n";
}

echo "\n--- Aid Requests for Facility 18 ---\n";
$facility18Requests = AidRequest::whereHas('beneficiary.financialAid', function ($query) {
    $query->where('id', 18);
})->get();

foreach ($facility18Requests as $request) {
    echo "ID: {$request->id}, Status: {$request->status}, Director Status: {$request->director_approval_status}\n";
}

echo "\n--- Pending Director Approvals for Facility 18 ---\n";
$pendingAidApprovals = AidRequest::where('director_approval_status', 'pending')
    ->whereHas('beneficiary.financialAid', function ($query) {
        $query->where('id', 18);
    })->get();

echo "Count: " . $pendingAidApprovals->count() . "\n";
foreach ($pendingAidApprovals as $request) {
    echo "ID: {$request->id}, Status: {$request->status}, Director Status: {$request->director_approval_status}\n";
}

echo "\n--- All Liquidations ---\n";
$allLiquidations = Liquidation::with('beneficiary.financialAid')->get();
foreach ($allLiquidations as $liquidation) {
    $facilityId = $liquidation->beneficiary->financial_aid_id ?? 'N/A';
    $facilityName = $liquidation->beneficiary->financialAid->center_name ?? 'Unknown';
    echo "ID: {$liquidation->id}, Status: {$liquidation->status}, Beneficiary Facility: {$facilityId} ({$facilityName})\n";
}

echo "\n--- Pending Director Liquidation Approvals for Facility 18 ---\n";
$pendingLiquidationApprovals = Liquidation::where('status', 'pending_director_approval')
    ->whereHas('beneficiary.financialAid', function ($query) {
        $query->where('id', 18);
    })->get();

echo "Count: " . $pendingLiquidationApprovals->count() . "\n";
foreach ($pendingLiquidationApprovals as $liquidation) {
    echo "ID: {$liquidation->id}, Status: {$liquidation->status}\n";
}