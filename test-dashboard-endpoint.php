<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;

echo "=== Testing Dashboard Endpoint as Director ===\n\n";

// Get director user
$director = User::with('systemRole')->whereHas('systemRole', function($q) {
    $q->where('name', 'director');
})->first();

if (!$director) {
    echo "ERROR: No director found!\n";
    exit;
}

echo "Director: {$director->firstname} {$director->lastname}\n";
echo "Financial Aid ID: {$director->financial_aid_id}\n\n";

// Manually set authenticated user
Auth::login($director);

// Instantiate controller
$controller = new \App\Http\Controllers\DirectorController();

// Call the dashboardData method
try {
    $response = $controller->dashboardData();
    $data = $response->getData(true);
    
    echo "=== API Response ===\n";
    echo "Success: " . ($data['success'] ? 'Yes' : 'No') . "\n";
    
    if (isset($data['data'])) {
        $stats = $data['data']['stats'] ?? [];
        echo "\nStats:\n";
        echo "  Staff Count: " . ($stats['staff_count'] ?? 'N/A') . "\n";
        echo "  Beneficiary Count: " . ($stats['beneficiary_count'] ?? 'N/A') . "\n";
        echo "  Pending Aid Approvals: " . ($stats['pending_aid_approvals'] ?? 'N/A') . "\n";
        echo "  Pending Liquidation Approvals: " . ($stats['pending_liquidation_approvals'] ?? 'N/A') . "\n";
        
        $facility = $data['data']['facility'] ?? [];
        echo "\nFacility:\n";
        echo "  ID: " . ($facility['id'] ?? 'N/A') . "\n";
        echo "  Name: " . ($facility['center_name'] ?? 'N/A') . "\n";
    }
    
    if (isset($data['message'])) {
        echo "\nMessage: {$data['message']}\n";
    }
    
    echo "\n=== Expected Values ===\n";
    echo "Staff: 3\n";
    echo "Beneficiaries: 1\n";
    echo "Pending Aid Approvals: 1\n";
    echo "Pending Liquidation Approvals: 0\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
