<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Facility;
use App\Models\User;
use App\Models\Beneficiary;

$facility = Facility::first();

if (!$facility) {
    echo "No facility found!\n";
    exit;
}

echo "=== Testing Dashboard Queries ===\n";
echo "Facility ID: {$facility->id}\n";
echo "Facility Name: {$facility->center_name}\n\n";

echo "--- Users (Employees) ---\n";
$totalUsers = User::count();
echo "Total users in database: {$totalUsers}\n";

$usersInFacility = User::where('financial_aid_id', $facility->id)->count();
echo "Users with financial_aid_id={$facility->id}: {$usersInFacility}\n";

$staffWithRole = User::whereHas('systemRole', function($q) {
    $q->whereIn('name', ['caseworker', 'finance']);
})->where('financial_aid_id', $facility->id)->count();
echo "Staff (caseworker + finance) in facility: {$staffWithRole}\n\n";

// Show actual users
echo "Users in facility {$facility->id}:\n";
$users = User::with('systemRole')->where('financial_aid_id', $facility->id)->get();
foreach ($users as $user) {
    $roleName = $user->systemRole ? $user->systemRole->name : 'none';
    echo "  - {$user->firstname} {$user->lastname} (Role: {$roleName})\n";
}

echo "\n--- Beneficiaries ---\n";
$totalBeneficiaries = Beneficiary::count();
echo "Total beneficiaries in database: {$totalBeneficiaries}\n";

$beneficiariesInFacility = Beneficiary::where('financial_aid_id', $facility->id)->count();
echo "Beneficiaries with financial_aid_id={$facility->id}: {$beneficiariesInFacility}\n\n";

// Show actual beneficiaries
echo "Beneficiaries in facility {$facility->id}:\n";
$beneficiaries = Beneficiary::where('financial_aid_id', $facility->id)->get();
foreach ($beneficiaries as $beneficiary) {
    echo "  - {$beneficiary->firstname} {$beneficiary->lastname}\n";
}
