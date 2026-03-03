<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$artist = App\Models\Artist::find(9);
if (!$artist) {
    echo "ARTIST_NOT_FOUND\n";
    exit(1);
}

$artist->avatar = 'artists/avatars/1772458739_test-upload2.png';
$artist->cover_image = 'artists/covers/1772453555_new-cover.jpg';
$artist->save();
$artist->refresh();

echo "UPDATED_ARTIST_ID={$artist->id}\n";
echo "AVATAR={$artist->avatar}\n";
echo "COVER_IMAGE={$artist->cover_image}\n";
echo "UPDATED_AT={$artist->updated_at}\n";
