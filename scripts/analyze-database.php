#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "📊 Database Analysis - TesoTunes\n";
echo str_repeat("=", 60) . "\n\n";

// Get all tables
$tables = DB::select('SHOW TABLES');
$tableCount = count($tables);

echo "Total Tables: $tableCount\n\n";

// Get table sizes
echo "🔍 Analyzing table sizes...\n";
$query = "SELECT 
    table_name,
    ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
    table_rows
FROM information_schema.TABLES 
WHERE table_schema = DATABASE()
ORDER BY (data_length + index_length) DESC 
LIMIT 30";

$results = DB::select($query);

echo "\nTop 30 Largest Tables:\n";
echo str_repeat("-", 60) . "\n";
printf("%-40s %10s %12s\n", "Table", "Size (MB)", "Rows");
echo str_repeat("-", 60) . "\n";

foreach ($results as $row) {
    printf("%-40s %10s %12s\n", 
        $row->table_name, 
        $row->size_mb, 
        number_format($row->table_rows)
    );
}

// Check for empty tables
echo "\n\n🔍 Checking for empty tables...\n";
$emptyTables = [];
foreach ($tables as $table) {
    $tableName = array_values((array)$table)[0];
    $count = DB::table($tableName)->count();
    if ($count === 0) {
        $emptyTables[] = $tableName;
    }
}

if (count($emptyTables) > 0) {
    echo "\nEmpty Tables (" . count($emptyTables) . "):\n";
    echo str_repeat("-", 60) . "\n";
    foreach (array_slice($emptyTables, 0, 20) as $table) {
        echo "- $table\n";
    }
    if (count($emptyTables) > 20) {
        echo "... and " . (count($emptyTables) - 20) . " more\n";
    }
} else {
    echo "✅ No empty tables found\n";
}

echo "\n✅ Analysis complete!\n";
