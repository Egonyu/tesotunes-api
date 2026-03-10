<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$a = App\Models\Artist::find(5);
echo "Artist: " . $a->stage_name . "\n";
echo "Earnings: UGX " . $a->earnings_balance . "\n";
echo "Plays: " . $a->total_plays_count . "\n";
$revs = App\Models\ArtistRevenue::where('artist_id', 5)->get();
echo "Revenue records: " . $revs->count() . "\n";
foreach ($revs as $r) {
    echo "  Type={$r->revenue_type} Gross=UGX{$r->amount_ugx} Fee=UGX{$r->platform_fee} Net=UGX{$r->net_amount} Status={$r->status}\n";
}
