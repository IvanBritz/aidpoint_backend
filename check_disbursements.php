<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Disbursement;

echo "Disbursements Status Summary:\n";
echo "============================\n";

$disbursements = Disbursement::selectRaw('status, count(*) as count')
    ->groupBy('status')
    ->get();

foreach ($disbursements as $d) {
    echo $d->status . ': ' . $d->count . "\n";
}

echo "\nTotal disbursements: " . Disbursement::count() . "\n";

// Check how many need liquidation
$needLiquidation = Disbursement::where('status', 'beneficiary_received')->count();
echo "Disbursements needing liquidation: " . $needLiquidation . "\n";

// Show some sample disbursements
echo "\nSample disbursement details:\n";
$samples = Disbursement::with('aidRequest')->take(3)->get();
foreach ($samples as $sample) {
    $beneficiary_id = $sample->aidRequest ? $sample->aidRequest->beneficiary_id : 'N/A';
    echo "ID: {$sample->id}, Status: {$sample->status}, Amount: {$sample->amount}, Beneficiary: {$beneficiary_id}\n";
}