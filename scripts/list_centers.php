<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = \DB::select('SELECT id, center_id, center_name FROM financial_aid ORDER BY id');
foreach ($rows as $r) {
    echo $r->id . ": " . $r->center_id . " - " . $r->center_name . "\n";
}
