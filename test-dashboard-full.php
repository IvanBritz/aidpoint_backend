<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\FinancialAid;
use App\Models\User;
use App\Models\Beneficiary;

echo "=== Testing Dashboard Data ===\n\n";

// Check facility 4 (director's assigned facility)
echo "--- Facility 4 (Director's financial_aid_id) ---\n";
$facility4 = FinancialAid::find(4);
if ($facility4) {
    echo "Name: {$facility4->center_name}\n";
    
    $staff4 = User::whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })->where('financial_aid_id', 4)->count();
    
    $ben4 = Beneficiary::where('financial_aid_id', 4)->count();
    
    echo "Staff: {$staff4}\n";
    echo "Beneficiaries: {$ben4}\n\n";
}

// Check facility 18 (URL param)
echo "--- Facility 18 (URL parameter) ---\n";
$facility18 = FinancialAid::find(18);
if ($facility18) {
    echo "Name: {$facility18->center_name}\n";
    
    $staff18 = User::whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })->where('financial_aid_id', 18)->count();
    
    $ben18 = Beneficiary::where('financial_aid_id', 18)->count();
    
    echo "Staff: {$staff18}\n";
    echo "Beneficiaries: {$ben18}\n\n";
}

// Check all employees and which facilities they belong to
echo "--- All Employees (caseworker/finance) ---\n";
$allStaff = User::with('systemRole')
    ->whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })
    ->get();

foreach ($allStaff as $staff) {
    $role = $staff->systemRole ? $staff->systemRole->name : 'none';
    echo "  - {$staff->firstname} {$staff->lastname} (Role: {$role}, Facility ID: {$staff->financial_aid_id})\n";
}

echo "\n--- All Beneficiaries ---\n";
$allBeneficiaries = Beneficiary::all();
foreach ($allBeneficiaries as $ben) {
    echo "  - {$ben->firstname} {$ben->lastname} (Facility ID: {$ben->financial_aid_id})\n";
}

echo "\n--- Recommendation ---\n";
echo "The director (financial_aid_id = 4) is accessing facility 18.\n";
echo "Employees and beneficiary are in facility 18.\n";
echo "The director user might need to be updated to have financial_aid_id = 18\n";
echo "OR the URL routing logic needs adjustment.\n";
