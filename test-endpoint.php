<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\FinancialAid;
use App\Models\User;
use App\Models\Beneficiary;
use Illuminate\Support\Facades\Auth;

echo "=== Testing Director Dashboard Endpoint Logic ===\n\n";

// Find the director user
$director = User::whereHas('systemRole', function($q) {
    $q->where('name', 'director');
})->first();

if (!$director) {
    echo "ERROR: No director found!\n";
    exit;
}

echo "Director: {$director->firstname} {$director->lastname} (ID: {$director->id})\n";

// Get the facility
$facility = FinancialAid::where('director_id', $director->id)->first();

if (!$facility) {
    echo "ERROR: No facility found for this director!\n";
    echo "Checking all facilities:\n";
    $allFacilities = FinancialAid::all();
    foreach ($allFacilities as $f) {
        echo "  - Facility ID: {$f->id}, Director ID: {$f->director_id}, Name: {$f->center_name}\n";
    }
    exit;
}

echo "Facility: {$facility->center_name} (ID: {$facility->id})\n\n";

// Test employee count
echo "--- Testing Employee Count ---\n";
$staffCount = User::whereHas('systemRole', function ($query) {
    $query->whereIn('name', ['caseworker', 'finance']);
})->where('financial_aid_id', $facility->id)->count();

echo "Staff count query result: {$staffCount}\n";

// Show actual employees
$employees = User::with('systemRole')
    ->whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })
    ->where('financial_aid_id', $facility->id)
    ->get();

echo "Employees found:\n";
foreach ($employees as $emp) {
    $role = $emp->systemRole ? $emp->systemRole->name : 'none';
    echo "  - {$emp->firstname} {$emp->lastname} (Role: {$role}, financial_aid_id: {$emp->financial_aid_id})\n";
}

// Test beneficiary count
echo "\n--- Testing Beneficiary Count ---\n";
$beneficiaryCount = Beneficiary::where('financial_aid_id', $facility->id)->count();
echo "Beneficiary count query result: {$beneficiaryCount}\n";

// Show actual beneficiaries
$beneficiaries = Beneficiary::where('financial_aid_id', $facility->id)->get();
echo "Beneficiaries found:\n";
foreach ($beneficiaries as $ben) {
    echo "  - {$ben->firstname} {$ben->lastname} (financial_aid_id: {$ben->financial_aid_id})\n";
}

echo "\n=== Summary ===\n";
echo "Expected Employees: 3\n";
echo "Actual Employees: {$staffCount}\n";
echo "Expected Beneficiaries: 1\n";
echo "Actual Beneficiaries: {$beneficiaryCount}\n";
