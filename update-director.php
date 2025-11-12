<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;

$director = User::find(2);

if ($director) {
    $oldFacilityId = $director->financial_aid_id;
    $director->financial_aid_id = 18;
    $director->save();
    
    echo "Director '{$director->firstname} {$director->lastname}' updated:\n";
    echo "  Old financial_aid_id: {$oldFacilityId}\n";
    echo "  New financial_aid_id: 18\n";
    echo "\nThe director now has access to facility 18 (DAVAO CHILD DEVELOPMENT CENTER)\n";
} else {
    echo "Director not found\n";
}
