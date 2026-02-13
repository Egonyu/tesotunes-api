#!/usr/bin/env php
<?php

/**
 * Factory Validation Script
 * 
 * Validates that all factories produce data matching database schema
 * Run: php scripts/validate-factory-fixes.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

echo "🔍 Factory Validation Script\n";
echo "============================\n\n";

// Test Artist Factory
echo "Testing ArtistFactory...\n";
try {
    $artist = \App\Models\Artist::factory()->make();
    $artistColumns = Schema::getColumnListing('artists');
    
    $missingColumns = [];
    foreach ($artist->getAttributes() as $key => $value) {
        if (!in_array($key, $artistColumns) && $key !== 'user_id') {
            $missingColumns[] = $key;
        }
    }
    
    if (empty($missingColumns)) {
        echo "✅ ArtistFactory: All columns match\n";
    } else {
        echo "❌ ArtistFactory: Missing columns: " . implode(', ', $missingColumns) . "\n";
    }
} catch (\Exception $e) {
    echo "❌ ArtistFactory Error: " . $e->getMessage() . "\n";
}

// Test ArtistProfile Factory
echo "\nTesting ArtistProfileFactory...\n";
try {
    $artistProfile = \Database\Factories\ArtistProfileFactory::new()->make();
    echo "✅ ArtistProfileFactory: Successfully created\n";
} catch (\Exception $e) {
    echo "❌ ArtistProfileFactory Error: " . $e->getMessage() . "\n";
}

// Test Album Factory
echo "\nTesting AlbumFactory...\n";
try {
    $album = \App\Models\Album::factory()->make();
    $albumColumns = Schema::getColumnListing('albums');
    
    $missingColumns = [];
    foreach ($album->getAttributes() as $key => $value) {
        if (!in_array($key, $albumColumns) && !in_array($key, ['user_id', 'artist_id'])) {
            $missingColumns[] = $key;
        }
    }
    
    if (empty($missingColumns)) {
        echo "✅ AlbumFactory: All columns match\n";
    } else {
        echo "❌ AlbumFactory: Missing columns: " . implode(', ', $missingColumns) . "\n";
    }
} catch (\Exception $e) {
    echo "❌ AlbumFactory Error: " . $e->getMessage() . "\n";
}

// Test Song Factory
echo "\nTesting SongFactory...\n";
try {
    $song = \App\Models\Song::factory()->make();
    $songColumns = Schema::getColumnListing('songs');
    
    $missingColumns = [];
    foreach ($song->getAttributes() as $key => $value) {
        if (!in_array($key, $songColumns) && !in_array($key, ['user_id', 'artist_id', 'album_id'])) {
            $missingColumns[] = $key;
        }
    }
    
    if (empty($missingColumns)) {
        echo "✅ SongFactory: All columns match\n";
    } else {
        echo "❌ SongFactory: Missing columns: " . implode(', ', $missingColumns) . "\n";
    }
} catch (\Exception $e) {
    echo "❌ SongFactory Error: " . $e->getMessage() . "\n";
}

// Test Payment Factory
echo "\nTesting PaymentFactory...\n";
try {
    $payment = \App\Models\Payment::factory()->make();
    $paymentColumns = Schema::getColumnListing('payments');
    
    $missingColumns = [];
    foreach ($payment->getAttributes() as $key => $value) {
        if (!in_array($key, $paymentColumns) && $key !== 'user_id') {
            $missingColumns[] = $key;
        }
    }
    
    if (empty($missingColumns)) {
        echo "✅ PaymentFactory: All columns match\n";
    } else {
        echo "❌ PaymentFactory: Missing columns: " . implode(', ', $missingColumns) . "\n";
    }
} catch (\Exception $e) {
    echo "❌ PaymentFactory Error: " . $e->getMessage() . "\n";
}

echo "\n============================\n";
echo "Validation Complete!\n";
echo "\nNext steps:\n";
echo "1. Run: php artisan test tests/Feature/Social/FollowArtistTest.php\n";
echo "2. Run: php artisan test tests/Feature/Music/ISRCGenerationTest.php\n";
echo "3. Run: php artisan test --stop-on-failure\n";
