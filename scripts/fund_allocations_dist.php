<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = \DB::select('SELECT financial_aid_id, COUNT(*) as c, SUM(allocated_amount) as total FROM fund_allocations GROUP BY financial_aid_id ORDER BY financial_aid_id');
foreach ($rows as $r) {
    echo 'financial_aid_id=' . ($r->financial_aid_id === null ? 'NULL' : $r->financial_aid_id) . ' count=' . $r->c . ' total=' . $r->total . "\n";
}
