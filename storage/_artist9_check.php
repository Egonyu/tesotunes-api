<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$a = App\Models\Artist::find(9);
if (!$a) {
    echo "ARTIST_NOT_FOUND\n";
    exit(0);
}

echo "ARTIST_ID={$a->id}\n";
echo "NAME={$a->stage_name}\n";
echo "AVATAR={$a->avatar}\n";
echo "COVER_IMAGE={$a->cover_image}\n";
echo "UPDATED_AT={$a->updated_at}\n";
