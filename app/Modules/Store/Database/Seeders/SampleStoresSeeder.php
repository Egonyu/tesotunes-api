<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Store\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

class SampleStoresSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get users with artist role or create test users
        $artists = User::whereHas('roles', function($q) {
            $q->where('name', 'artist');
        })->take(5)->get();

        if ($artists->isEmpty()) {
            $this->command->warn('No artists found. Using first available user...');
            $firstUser = User::first();
            
            if (!$firstUser) {
                $this->command->error('No users found in database. Please create at least one user first.');
                return;
            }
            
            $artists = collect([$firstUser]);
        }

        $storeData = [
            [
                'name' => 'Muzik Empire Store',
                'slug' => 'muzik-empire-store',
                'description' => 'Official merchandise and music from Muzik Empire. Premium quality streetwear and exclusive beats.',
                'tagline' => 'Empire Vibes Only',
                'contact_email' => 'shop@muzikempire.ug',
                'contact_phone' => '+256700123456',
                'status' => 'active',
            ],
            [
                'name' => 'Beat Factory',
                'slug' => 'beat-factory',
                'description' => 'Professional beats, samples, and sound packs for producers and artists. Industry-grade quality.',
                'tagline' => 'Where Beats Come to Life',
                'contact_email' => 'sales@beatfactory.ug',
                'contact_phone' => '+256700234567',
                'status' => 'active',
            ],
            [
                'name' => 'Artist Merch Hub',
                'slug' => 'artist-merch-hub',
                'description' => 'Custom designed merchandise for music lovers. T-shirts, hoodies, and accessories.',
                'tagline' => 'Wear Your Music',
                'contact_email' => 'hello@artistmerch.ug',
                'contact_phone' => '+256700345678',
                'status' => 'active',
            ],
            [
                'name' => 'Studio Services Pro',
                'slug' => 'studio-services-pro',
                'description' => 'Professional music production, mixing, and mastering services. Award-winning engineers.',
                'tagline' => 'Studio Excellence',
                'contact_email' => 'booking@studiopro.ug',
                'contact_phone' => '+256700456789',
                'status' => 'active',
            ],
            [
                'name' => 'VIP Experiences',
                'slug' => 'vip-experiences',
                'description' => 'Exclusive meet & greets, studio visits, and unique experiences with top artists.',
                'tagline' => 'Meet Your Idols',
                'contact_email' => 'vip@experiences.ug',
                'contact_phone' => '+256700567890',
                'status' => 'draft', // One draft for testing approval
            ],
        ];

        foreach ($storeData as $index => $data) {
            $artist = $artists[$index % $artists->count()];
            
            Store::create([
                'uuid' => Str::uuid(),
                'user_id' => $artist->id,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'],
                'status' => $data['status'],
                'store_type' => 'artist',
                'subscription_tier' => $index < 2 ? 'premium' : 'free',
                'settings' => [
                    'tagline' => $data['tagline'] ?? null,
                    'contact_email' => $data['contact_email'],
                    'contact_phone' => $data['contact_phone'],
                    'shipping_enabled' => true,
                    'accepts_credits' => true,
                    'accepts_mobile_money' => true,
                    'auto_accept_orders' => false,
                ],
            ]);
        }

        $this->command->info('✓ Created ' . Store::count() . ' sample stores');
    }
}
