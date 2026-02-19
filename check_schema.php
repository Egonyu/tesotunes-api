<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$columns = DB::select('DESCRIBE artists');
foreach ($columns as $c) {
    echo $c->Field.' | '.$c->Type.PHP_EOL;
}
