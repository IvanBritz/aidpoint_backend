#!/usr/bin/env php
<?php
// Usage:
//   php scripts/clear_received_disbursements.php [--limit=3] [--facility-id=ID]
// Deletes the N most recent disbursements with status 'beneficiary_received'.
// If --facility-id is provided, the deletion is scoped to that center.

use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Disbursement;

$limit = 3;
$facilityId = null;

foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
    if (preg_match('/^--facility-id=(\d+)/', $arg, $m)) {
        $facilityId = (int) $m[1];
    }
}

$query = Disbursement::where('status', 'beneficiary_received');

if ($facilityId) {
    $query->whereHas('aidRequest.beneficiary', function ($q) use ($facilityId) {
        $q->where('financial_aid_id', $facilityId);
    });
}

$items = $query->orderByDesc('beneficiary_received_at')
              ->take($limit)
              ->get(['id','aid_request_id','amount','beneficiary_received_at']);

$ids = $items->pluck('id')->all();
$count = $items->count();

foreach ($items as $d) {
    $d->delete();
}

echo "Deleted {$count} disbursement(s): " . implode(',', $ids) . PHP_EOL;
