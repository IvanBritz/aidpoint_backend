<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;

$director = User::with('systemRole')->whereHas('systemRole', function($q) {
    $q->where('name', 'director');
})->first();

if ($director) {
    echo "Director: {$director->firstname} {$director->lastname}\n";
    echo "User ID: {$director->id}\n";
    echo "Financial Aid ID: " . ($director->financial_aid_id ?: 'NULL') . "\n";
} else {
    echo "No director found\n";
}
