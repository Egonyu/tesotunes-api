<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Store\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class RealWorldStoresSeeder extends Seeder
{
    /**
     * Run the database seeds - Real world Uganda music industry scenario
     */
    public function run(): void
    {
        // Create realistic artist/producer users if they don't exist
        $artistUsers = $this->createArtistUsers();

        $storeData = [
            [
                'name' => 'Navio Official Store',
                'slug' => 'navio-official',
                'description' => 'Official merchandise from Uganda\'s Hip Hop King. Get authentic Navio branded apparel, exclusive beats, and limited edition items. Support Ugandan Hip Hop culture.',
                'tagline' => 'Hip Hop Made in Uganda 🇺🇬',
                'contact_email' => 'store@navioofficial.com',
                'contact_phone' => '+256700123456',
                'status' => 'active',
                'user_key' => 'navio',
                'banner_description' => 'Legendary Ugandan rapper with over 15 years in the game',
            ],
            [
                'name' => 'Nessim Production House',
                'slug' => 'nessim-beats',
                'description' => 'Premium Afrobeat and Dancehall beats from one of Uganda\'s top producers. Worked with artists like Jose Chameleone, Sheebah, and Winnie Nwagi. Industry-standard quality.',
                'tagline' => 'The Sound of East Africa 🎵',
                'contact_email' => 'beats@nessimproduction.com',
                'contact_phone' => '+256700234567',
                'status' => 'active',
                'user_key' => 'nessim',
                'banner_description' => 'Award-winning producer - PAM Awards Best Producer 2023',
            ],
            [
                'name' => 'Kampala Merch Co.',
                'slug' => 'kampala-merch',
                'description' => 'Official merchandise hub for multiple Ugandan artists. T-shirts, hoodies, caps from your favorite local artists. 100% authentic, printed in Uganda.',
                'tagline' => 'Rep Your City, Rep Your Artist 🎨',
                'contact_email' => 'hello@kampalamerch.ug',
                'contact_phone' => '+256700345678',
                'status' => 'active',
                'user_key' => 'kampala_merch',
                'banner_description' => 'Supporting Ugandan artists through quality merchandise',
            ],
            [
                'name' => 'Swangz Avenue Studios',
                'slug' => 'swangz-studios',
                'description' => 'Professional studio services from Uganda\'s leading music production house. Mixing, mastering, and full production services. Home to hits by Winnie Nwagi, Vinka, and Azawi.',
                'tagline' => 'Where Hits Are Made ⭐',
                'contact_email' => 'booking@swangzavenue.com',
                'contact_phone' => '+256700456789',
                'status' => 'active',
                'user_key' => 'swangz',
                'banner_description' => 'Uganda\'s #1 music production powerhouse since 2008',
            ],
            [
                'name' => 'Eddy Kenzo Experiences',
                'slug' => 'eddy-kenzo-vip',
                'description' => 'Get up close and personal with BET Award winner Eddy Kenzo. Exclusive meet & greets, studio sessions, and VIP experiences. Limited availability.',
                'tagline' => 'Sitya Loss with the Legend 🏆',
                'contact_email' => 'vip@eddykenzo.com',
                'contact_phone' => '+256700567890',
                'status' => 'active',
                'user_key' => 'kenzo',
                'banner_description' => 'BET Award winner, Big Talent boss',
            ],
            [
                'name' => 'Cindy Sanyu Official',
                'slug' => 'cindy-official',
                'description' => 'The King Herself! Official store for Cindy Sanyu merchandise, music production tutorials, and exclusive content. Uganda\'s dancehall queen.',
                'tagline' => 'King Herself 👑',
                'contact_email' => 'shop@cindysanyu.com',
                'contact_phone' => '+256700678901',
                'status' => 'active',
                'user_key' => 'cindy',
                'banner_description' => 'UMA President, Dancehall Queen, 2x HiPipo Music Awards winner',
            ],
            [
                'name' => 'Artin Pro Beats',
                'slug' => 'artin-pro',
                'description' => 'Ugandan producer specializing in Afrobeats, Amapiano, and Dancehall. Professional beats at affordable prices. Payment in UGX or credits.',
                'tagline' => 'Affordable Quality Beats 🎹',
                'contact_email' => 'beats@artinpro.ug',
                'contact_phone' => '+256700789012',
                'status' => 'active',
                'user_key' => 'artin',
                'banner_description' => 'Rising producer, over 200 tracks produced',
            ],
            [
                'name' => 'Fenon Records Studio',
                'slug' => 'fenon-records',
                'description' => 'Legendary Ugandan studio behind countless hits. Full production, mixing, mastering, and recording services. The studio of choice for serious artists.',
                'tagline' => 'Studio of Legends Since 2002 🎙️',
                'contact_email' => 'studio@fenonrecords.com',
                'contact_phone' => '+256700890123',
                'status' => 'active',
                'user_key' => 'fenon',
                'banner_description' => 'Uganda\'s most awarded studio - 20+ years of excellence',
            ],
            [
                'name' => 'Bebe Cool Store',
                'slug' => 'bebe-cool-store',
                'description' => 'Official merchandise and exclusive content from Gagamel boss Bebe Cool. Premium quality apparel and memorabilia from Uganda\'s music icon.',
                'tagline' => 'Gagamel Gang 🔥',
                'contact_email' => 'store@bebecool.net',
                'contact_phone' => '+256700901234',
                'status' => 'active',
                'user_key' => 'bebecool',
                'banner_description' => 'Multiple award winner, Gagamel Entertainment CEO',
            ],
            [
                'name' => 'Levixone Gospel Store',
                'slug' => 'levixone-gospel',
                'description' => 'Official store for gospel artist Levixone. Music bundles, worship experiences, and gospel merchandise. Spreading the word through music.',
                'tagline' => 'Turn the Replay 🙏',
                'contact_email' => 'store@levixone.com',
                'contact_phone' => '+256701012345',
                'status' => 'draft', // Pending approval for testing
                'user_key' => 'levixone',
                'banner_description' => 'Uganda\'s leading gospel artist',
            ],
        ];

        foreach ($storeData as $index => $data) {
            $user = $artistUsers[$data['user_key']] ?? $artistUsers['default'];
            
            Store::create([
                'uuid' => Str::uuid(),
                'user_id' => $user->id,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'],
                'status' => $data['status'],
                'store_type' => 'artist',
                'subscription_tier' => $index < 5 ? 'premium' : 'free', // First 5 are premium
                'settings' => [
                    'tagline' => $data['tagline'] ?? null,
                    'banner_text' => $data['banner_description'] ?? null,
                    'contact_email' => $data['contact_email'],
                    'contact_phone' => $data['contact_phone'],
                    'shipping_enabled' => true,
                    'accepts_credits' => true,
                    'accepts_mobile_money' => true,
                    'auto_accept_orders' => $index < 3, // Top stores auto-accept
                    'social_links' => [
                        'instagram' => '@' . $data['slug'],
                        'twitter' => '@' . $data['slug'],
                        'youtube' => $data['slug'],
                    ],
                ],
            ]);
        }

        $this->command->info('✓ Created ' . Store::count() . ' real-world stores');
    }

    /**
     * Create realistic artist/producer users
     */
    private function createArtistUsers(): array
    {
        $users = [];
        
        $artistData = [
            'navio' => ['name' => 'Daniel Lubwama (Navio)', 'email' => 'navio@lineone.ug'],
            'nessim' => ['name' => 'Nessim Producer', 'email' => 'nessim@lineone.ug'],
            'kampala_merch' => ['name' => 'Kampala Merch Team', 'email' => 'kampala@lineone.ug'],
            'swangz' => ['name' => 'Swangz Avenue', 'email' => 'swangz@lineone.ug'],
            'kenzo' => ['name' => 'Eddy Kenzo', 'email' => 'kenzo@lineone.ug'],
            'cindy' => ['name' => 'Cindy Sanyu', 'email' => 'cindy@lineone.ug'],
            'artin' => ['name' => 'Artin Pro', 'email' => 'artin@lineone.ug'],
            'fenon' => ['name' => 'Fenon Records', 'email' => 'fenon@lineone.ug'],
            'bebecool' => ['name' => 'Bebe Cool', 'email' => 'bebecool@lineone.ug'],
            'levixone' => ['name' => 'Levixone', 'email' => 'levixone@lineone.ug'],
        ];

        // Check if users already exist, create if not
        foreach ($artistData as $key => $data) {
            $user = User::where('email', $data['email'])->first();
            
            if (!$user) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make('password123'), // Default password
                    'email_verified_at' => now(),
                    'role' => 'artist', // Set role directly if column exists
                ]);
                
                $this->command->info("Created user: {$data['name']}");
            }
            
            $users[$key] = $user;
        }
        
        // Get default user as fallback
        $users['default'] = User::first() ?? $users['navio'];
        
        return $users;
    }
}
