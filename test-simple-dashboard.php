<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;
use App\Models\FinancialAid;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

echo "=== Testing Dashboard API as Director ===\n\n";

// Get director user
$director = User::with('systemRole')->whereHas('systemRole', function($q) {
    $q->where('name', 'director');
})->first();

if (!$director) {
    echo "ERROR: No director found!\n";
    exit;
}

echo "Director: {$director->firstname} {$director->lastname}\n";
echo "Director Financial Aid ID: {$director->financial_aid_id}\n\n";

// Manually set authenticated user
Auth::login($director);

// Test data directly
echo "--- Testing Facility 18 Data ---\n";
$facility18 = FinancialAid::find(18);
if ($facility18) {
    echo "Facility Name: {$facility18->center_name}\n";
    echo "Facility Director ID: {$facility18->director_id}\n";
    
    // Count staff
    $staff18 = User::whereHas('systemRole', function ($query) {
        $query->whereIn('name', ['caseworker', 'finance']);
    })->where('financial_aid_id', 18)->count();
    
    // Count beneficiaries
    $beneficiaries18 = User::where('financial_aid_id', 18)
        ->where(function ($q) {
            $q->where('systemrole_id', 4)
              ->orWhereHas('systemRole', function ($query) {
                  $query->where('name', 'beneficiary');
              });
        })
        ->count();
    
    echo "Staff Count: {$staff18}\n";
    echo "Beneficiaries Count: {$beneficiaries18}\n\n";
}

// Create fake request with facility_id parameter
$request = new Request();
$request->merge(['facility_id' => '18']);

// Call controller method
$controller = new \App\Http\Controllers\DirectorController();

try {
    echo "--- Calling Dashboard API ---\n";
    $response = $controller->dashboardData($request);
    $data = $response->getData(true);
    
    echo "Success: " . ($data['success'] ? 'Yes' : 'No') . "\n";
    
    if (isset($data['data']['stats'])) {
        $stats = $data['data']['stats'];
        echo "Staff Count: " . ($stats['staff_count'] ?? 'N/A') . "\n";
        echo "Beneficiary Count: " . ($stats['beneficiary_count'] ?? 'N/A') . "\n";
        echo "Pending Aid Approvals: " . ($stats['pending_aid_approvals'] ?? 'N/A') . "\n";
        echo "Pending Liquidation Approvals: " . ($stats['pending_liquidation_approvals'] ?? 'N/A') . "\n";
    }
    
    if (isset($data['message'])) {
        echo "Message: {$data['message']}\n";
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}