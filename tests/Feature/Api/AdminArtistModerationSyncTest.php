<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\ArtistProfile;
use App\Models\KYCDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminArtistModerationSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('artist_profiles')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_02_23_100000_create_missing_sacco_tables_and_fixes.php',
                '--realpath' => false,
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('kyc_documents')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_03_13_180000_create_kyc_documents_table.php',
                '--realpath' => false,
                '--force' => true,
            ]);
        }

        Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Admin role', 'is_active' => true, 'priority' => 100]
        );

        Role::firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Artist role', 'is_active' => true, 'priority' => 50]
        );

        Role::firstOrCreate(
            ['name' => 'user'],
            ['display_name' => 'User', 'description' => 'User role', 'is_active' => true, 'priority' => 1]
        );
    }

    public function test_admin_approval_syncs_user_artist_profile_and_kyc_state(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $user = User::factory()->create([
            'application_status' => 'pending',
            'full_name' => 'Pending Artist',
            'phone' => '+256700000002',
            'mobile_money_number' => '+256700000002',
            'mobile_money_provider' => 'mtn',
        ]);

        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'is_verified' => false,
            'verification_status' => 'pending',
            'can_upload' => false,
        ]);

        ArtistProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
                'real_name' => 'Pending Artist',
                'verification_status' => 'pending',
                'mobile_money_provider' => 'mtn',
                'mobile_money_number' => '+256700000002',
                'payout_method' => 'mobile_money',
            ]
        );

        KYCDocument::create([
            'user_id' => $user->id,
            'document_type' => KYCDocument::TYPE_NATIONAL_ID_FRONT,
            'file_path' => 'kyc/test/front.jpg',
            'status' => KYCDocument::STATUS_PENDING,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/artists/{$artist->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('artists', [
            'id' => $artist->id,
            'status' => 'active',
            'is_verified' => true,
            'can_upload' => true,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'application_status' => 'approved',
            'is_artist' => true,
        ]);

        $this->assertDatabaseHas('artist_profiles', [
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'verification_status' => 'verified',
        ]);

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $user->id,
            'document_type' => KYCDocument::TYPE_NATIONAL_ID_FRONT,
            'status' => KYCDocument::STATUS_VERIFIED,
        ]);
    }

    public function test_admin_suspend_syncs_user_and_artist_profile_state(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $user = User::factory()->create([
            'application_status' => 'approved',
            'is_artist' => true,
        ]);

        $user->assignRole('artist', $admin->id);

        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'is_verified' => true,
            'verification_status' => 'approved',
            'can_upload' => true,
        ]);

        ArtistProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
                'verification_status' => 'verified',
                'is_active' => true,
            ]
        );

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/artists/{$artist->id}/suspend")
            ->assertOk();

        $this->assertDatabaseHas('artists', [
            'id' => $artist->id,
            'status' => 'suspended',
            'can_upload' => false,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'application_status' => 'suspended',
            'is_artist' => false,
        ]);

        $artistRole = Role::where('name', 'artist')->firstOrFail();
        $userRole = Role::where('name', 'user')->firstOrFail();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $artistRole->id,
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $userRole->id,
            'is_active' => 1,
        ]);

        $user->refresh();
        $this->assertSame('user', $user->role);

        $this->assertDatabaseHas('artist_profiles', [
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'verification_status' => 'suspended',
            'is_active' => false,
        ]);
    }

    public function test_admin_reject_records_reason_and_keeps_reapply_state(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $user = User::factory()->create([
            'application_status' => 'pending',
        ]);

        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'is_verified' => false,
            'verification_status' => 'pending',
            'can_upload' => false,
        ]);

        ArtistProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
                'verification_status' => 'pending',
                'is_active' => true,
            ]
        );

        KYCDocument::create([
            'user_id' => $user->id,
            'document_type' => KYCDocument::TYPE_NATIONAL_ID_FRONT,
            'file_path' => 'kyc/test/front.jpg',
            'status' => KYCDocument::STATUS_PENDING,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/artists/{$artist->id}/reject", [
                'reason' => 'Incomplete KYC documents',
            ])
            ->assertOk();

        $this->assertDatabaseHas('artists', [
            'id' => $artist->id,
            'status' => 'rejected',
            'rejection_reason' => 'Incomplete KYC documents',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'application_status' => 'rejected',
            'rejection_reason' => 'Incomplete KYC documents',
            'is_artist' => false,
        ]);

        $artistRole = Role::where('name', 'artist')->firstOrFail();
        $userRole = Role::where('name', 'user')->firstOrFail();

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $user->id,
            'role_id' => $artistRole->id,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $userRole->id,
            'is_active' => 1,
        ]);

        $user->refresh();
        $this->assertSame('user', $user->role);

        $this->assertDatabaseHas('artist_profiles', [
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'verification_status' => 'rejected',
        ]);

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $user->id,
            'document_type' => KYCDocument::TYPE_NATIONAL_ID_FRONT,
            'status' => KYCDocument::STATUS_REJECTED,
            'rejection_reason' => 'Incomplete KYC documents',
        ]);
    }

    public function test_admin_approval_promotes_primary_role_to_artist_when_user_role_already_exists(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $user = User::factory()->create([
            'application_status' => 'pending',
        ]);

        $userRole = Role::where('name', 'user')->firstOrFail();
        $artistRole = Role::where('name', 'artist')->firstOrFail();

        $user->roles()->syncWithoutDetaching([
            $userRole->id => [
                'assigned_at' => now(),
                'assigned_by' => $admin->id,
                'is_active' => true,
            ],
        ]);

        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'is_verified' => false,
            'verification_status' => 'pending',
            'can_upload' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/artists/{$artist->id}/approve")
            ->assertOk();

        $user->refresh();

        $this->assertSame('artist', $user->role);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $artistRole->id,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $userRole->id,
            'is_active' => 0,
        ]);
    }
}
