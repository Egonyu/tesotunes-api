<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('email', 'user@tesotunes.com')->first();
if ($user) {
    $token = $user->createToken('test')->plainTextToken;
    echo 'TOKEN: '.$token.PHP_EOL;
    echo 'USER_ID: '.$user->id.PHP_EOL;
} else {
    echo 'No user found'.PHP_EOL;
}
