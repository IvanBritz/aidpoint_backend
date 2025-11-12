<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\FinancialAid;
use App\Models\User;

echo "=== Checking URL Parameter (18) ===\n\n";

// Check if 18 is a facility_id
$facility = FinancialAid::find(18);
if ($facility) {
    echo "Facility ID 18 found:\n";
    echo "  Name: {$facility->center_name}\n";
    echo "  Director ID: " . ($facility->director_id ?: 'NULL') . "\n\n";
}

// Check if 18 is a user_id
$user = User::find(18);
if ($user) {
    echo "User ID 18 found:\n";
    echo "  Name: {$user->firstname} {$user->lastname}\n";
    echo "  Role: " . ($user->systemRole ? $user->systemRole->name : 'none') . "\n";
    echo "  Financial Aid ID: " . ($user->financial_aid_id ?: 'NULL') . "\n\n";
}

// Based on the URL pattern: http://localhost:3000/18/dashboard
// This is likely the facility_id parameter
echo "=== URL Pattern Analysis ===\n";
echo "URL: http://localhost:3000/18/dashboard\n";
echo "Pattern suggests: /[facility_id]/dashboard\n\n";

if ($facility) {
    echo "Testing queries with facility_id = 18:\n\n";
    
    // Staff count
    $staffCount = User::whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })->where('financial_aid_id', 18)->count();
    
    echo "Staff Count: {$staffCount}\n";
    
    // Show staff
    $staff = User::with('systemRole')
        ->whereHas('systemRole', function ($query) {
            $query->whereIn('name', ['caseworker', 'finance']);
        })
        ->where('financial_aid_id', 18)
        ->get();
    
    foreach ($staff as $s) {
        $role = $s->systemRole ? $s->systemRole->name : 'none';
        echo "  - {$s->firstname} {$s->lastname} (Role: {$role})\n";
    }
    
    // Beneficiary count
    $beneficiaryCount = \App\Models\Beneficiary::where('financial_aid_id', 18)->count();
    echo "\nBeneficiary Count: {$beneficiaryCount}\n";
    
    $beneficiaries = \App\Models\Beneficiary::where('financial_aid_id', 18)->get();
    foreach ($beneficiaries as $b) {
        echo "  - {$b->firstname} {$b->lastname}\n";
    }
}
