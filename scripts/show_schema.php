<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = \DB::select('PRAGMA table_info(fund_allocations)');
foreach ($rows as $r) {
    echo $r->cid . ": " . $r->name . " (" . $r->type . ") default=" . $r->dflt_value . " notnull=" . $r->notnull . "\n";
}
