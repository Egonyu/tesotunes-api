<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // General
            ['key' => 'site_name',              'value' => 'TesoTunes',                 'group' => 'general',       'type' => 'string',  'is_public' => true,  'description' => 'Site name'],
            ['key' => 'site_description',       'value' => 'African Music Distribution Platform', 'group' => 'general', 'type' => 'string', 'is_public' => true, 'description' => 'Site description'],
            ['key' => 'site_currency',          'value' => 'UGX',                       'group' => 'general',       'type' => 'string',  'is_public' => true,  'description' => 'Default currency'],
            ['key' => 'site_timezone',          'value' => 'Africa/Kampala',            'group' => 'general',       'type' => 'string',  'is_public' => true,  'description' => 'Default timezone'],
            ['key' => 'maintenance_mode',       'value' => 'false',                     'group' => 'general',       'type' => 'boolean', 'is_public' => true,  'description' => 'Maintenance mode'],

            // Users
            ['key' => 'allow_registration',     'value' => 'true',                      'group' => 'users',         'type' => 'boolean', 'is_public' => true,  'description' => 'Allow new user registration'],
            ['key' => 'require_email_verify',   'value' => 'false',                     'group' => 'users',         'type' => 'boolean', 'is_public' => false, 'description' => 'Require email verification'],
            ['key' => 'max_login_attempts',     'value' => '5',                         'group' => 'security',      'type' => 'integer', 'is_public' => false, 'description' => 'Max login attempts before lockout'],

            // Credits
            ['key' => 'initial_credits',        'value' => '100',                       'group' => 'credits',       'type' => 'integer', 'is_public' => true,  'description' => 'Credits given to new users'],
            ['key' => 'credits_per_ugx',        'value' => '1',                         'group' => 'credits',       'type' => 'integer', 'is_public' => true,  'description' => 'Credits per UGX'],

            // Payments
            ['key' => 'min_payout_amount',      'value' => '50000',                     'group' => 'payments',      'type' => 'integer', 'is_public' => true,  'description' => 'Minimum payout amount (UGX)'],
            ['key' => 'platform_commission',    'value' => '30',                        'group' => 'payments',      'type' => 'integer', 'is_public' => true,  'description' => 'Platform commission percentage'],

            // Artists
            ['key' => 'artist_verification_required', 'value' => 'true',               'group' => 'artists',       'type' => 'boolean', 'is_public' => true,  'description' => 'Require artist verification'],
            ['key' => 'max_upload_size_mb',     'value' => '50',                        'group' => 'storage',       'type' => 'integer', 'is_public' => true,  'description' => 'Max upload size in MB'],

            // Mobile
            ['key' => 'mobile_verification',    'value' => 'false',                     'group' => 'mobile',        'type' => 'boolean', 'is_public' => false, 'description' => 'Enable mobile number verification'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
