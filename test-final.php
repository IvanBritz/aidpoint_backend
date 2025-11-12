<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\FinancialAid;
use App\Models\User;

echo "=== Final Dashboard Test ===\n\n";

// Test with director's facility (ID=4)
$director = User::with('systemRole')->whereHas('systemRole', function($q) {
    $q->where('name', 'director');
})->first();

echo "Director: {$director->firstname} {$director->lastname}\n";
echo "Director's financial_aid_id: {$director->financial_aid_id}\n\n";

if ($director->financial_aid_id) {
    $facility = FinancialAid::find($director->financial_aid_id);
    echo "Facility: {$facility->center_name} (ID: {$facility->id})\n\n";
    
    // Test employee count
    $staffCount = User::whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })->where('financial_aid_id', $facility->id)->count();
    
    echo "Staff Count: {$staffCount}\n";
    
    // Test beneficiary count (systemrole_id = 4)
    $beneficiaryCount = User::where('systemrole_id', 4)
        ->where('financial_aid_id', $facility->id)
        ->count();
    
    echo "Beneficiary Count: {$beneficiaryCount}\n\n";
}

// Test with facility 18 (URL parameter)
echo "--- Testing Facility 18 (URL in dashboard) ---\n";
$facility18 = FinancialAid::find(18);
if ($facility18) {
    echo "Facility: {$facility18->center_name}\n";
    
    $staff18 = User::whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })->where('financial_aid_id', 18)->count();
    
    $ben18 = User::where('systemrole_id', 4)
        ->where('financial_aid_id', 18)
        ->count();
    
    echo "Staff: {$staff18}\n";
    echo "Beneficiaries: {$ben18}\n\n";
}

echo "--- Solution ---\n";
echo "The director should have financial_aid_id = 18 to match the employees and beneficiaries.\n";
echo "Or use the URL parameter to determine which facility to query.\n";
