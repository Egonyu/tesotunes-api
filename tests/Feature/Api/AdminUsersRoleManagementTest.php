<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUsersRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(
            ['name' => 'user'],
            ['display_name' => 'User', 'description' => 'Standard user', 'is_active' => true, 'priority' => 1]
        );
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Verified artist', 'is_active' => true, 'priority' => 2]
        );
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin', $this->admin->id);
        $this->admin->clearPermissionCache();
    }

    public function test_admin_can_create_user_with_artist_role_without_users_role_column(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Managed Artist',
            'email' => 'managed-artist@example.com',
            'password' => 'password123',
            'role' => 'artist',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'User created successfully.')
            ->assertJsonPath('data.artist.status', 'active');

        $user = User::where('email', 'managed-artist@example.com')->firstOrFail();

        $this->assertSame('artist', $user->fresh()->role);
        $this->assertTrue($user->fresh()->is_artist);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('artists', [
            'user_id' => $user->id,
        ]);
    }

    public function test_admin_can_create_artist_even_when_artist_role_row_is_missing(): void
    {
        Role::where('name', 'artist')->delete();

        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Recovered Artist',
            'email' => 'recovered-artist@example.com',
            'password' => 'password123',
            'role' => 'artist',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'User created successfully.')
            ->assertJsonPath('data.artist.status', 'active');

        $user = User::where('email', 'recovered-artist@example.com')->firstOrFail();
        $artistRole = Role::where('name', 'artist')->first();

        $this->assertNotNull($artistRole);
        $this->assertSame('artist', $user->fresh()->role);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $artistRole?->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_user_role_without_users_role_column(): void
    {
        $user = User::factory()->create([
            'is_artist' => false,
        ]);
        $user->assignRole('user', $this->admin->id);
        $user->clearPermissionCache();

        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$user->id}", [
            'role' => 'artist',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'User updated successfully.');

        $user = $user->fresh();

        $this->assertSame('artist', $user->role);
        $this->assertTrue($user->is_artist);
        $this->assertDatabaseHas('artists', [
            'user_id' => $user->id,
        ]);
    }

    public function test_admin_role_update_preserves_unmanaged_roles(): void
    {
        $financeRole = Role::factory()->create([
            'name' => 'finance',
            'display_name' => 'Finance',
        ]);

        $user = User::factory()->create();
        $user->assignRole('user', $this->admin->id);
        $user->roles()->attach($financeRole->id, [
            'is_active' => true,
            'assigned_at' => now(),
            'assigned_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->putJson("/api/admin/users/{$user->id}", [
            'role' => 'artist',
        ])->assertOk();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $financeRole->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_user_role_filter_uses_active_roles_only(): void
    {
        $artistRole = Role::where('name', 'artist')->firstOrFail();
        $user = User::factory()->create();

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $artistRole->id,
            'is_active' => false,
            'assigned_at' => now(),
            'assigned_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/users?role=artist');

        $response->assertOk();

        $returnedIds = collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        $this->assertNotContains($user->id, $returnedIds);
    }

    public function test_admin_user_show_includes_linked_artist_reference(): void
    {
        $user = User::factory()->create([
            'is_artist' => true,
        ]);
        $user->assignRole('artist', $this->admin->id);

        $artist = \App\Models\Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/users/{$user->id}");

        $response->assertOk()
            ->assertJsonPath('data.artist.id', $artist->id)
            ->assertJsonPath('data.artist.stage_name', $artist->stage_name)
            ->assertJsonPath('data.artist.status', 'active');
    }

    public function test_admin_user_show_includes_review_summary_and_attention_flags(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'phone_verified_at' => now(),
            'kyc_status' => 'pending_review',
            'kyc_submitted_at' => now()->subHour(),
            'profile_completion_percentage' => 70,
            'credits' => 25,
            'ugx_balance' => 15000,
        ]);
        $user->assignRole('user', $this->admin->id);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/users/{$user->id}");

        $response->assertOk()
            ->assertJsonPath('data.review.identity.status', 'pending_review')
            ->assertJsonPath('data.review.identity.documents.total', 0)
            ->assertJsonPath('data.review.account.email_verified', false)
            ->assertJsonPath('data.review.account.phone_verified', true)
            ->assertJsonPath('data.review.account.profile_completion_percentage', 70)
            ->assertJsonPath('data.review.wallet.balance_ugx', 15000)
            ->assertJsonPath('data.review.wallet.movements.withdrawn_ugx', 0)
            ->assertJsonPath('data.review.wallet.movements.withdrawals_pending_ugx', 0)
            ->assertJsonPath('data.review.wallet.movements.earnings_pending_ugx', 0)
            ->assertJsonPath('data.review.wallet.credits', 25)
            ->assertJsonPath('data.review.wallet.payments.total', 0)
            ->assertJsonPath('data.review.activity.orders.total', 0)
            ->assertJsonFragment(['title' => 'Email unverified'])
            ->assertJsonFragment(['title' => 'KYC awaiting review']);
    }

    public function test_admin_wallet_movements_separate_incoming_outgoing_and_pending_payments(): void
    {
        $user = User::factory()->create(['ugx_balance' => 12000]);

        foreach ([
            ['credits_sale', 'completed', 'platform_credits', 16000],
            ['withdrawal', 'completed', 'mobile_money', 3000],
            ['withdrawal', 'processing', 'mobile_money', 1000],
            ['credits_purchase', 'completed', 'wallet', 500],
            ['credits_sale', 'failed', 'platform_credits', 9000],
        ] as [$type, $status, $method, $amount]) {
            Payment::withoutEvents(fn () => Payment::factory()->create([
                'user_id' => $user->id,
                'payment_type' => $type,
                'status' => $status,
                'payment_method' => $method,
                'amount' => $amount,
                'currency' => 'UGX',
            ]));
        }

        $this->actingAs($this->admin)->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.review.wallet.balance_ugx', 12000)
            ->assertJsonPath('data.review.wallet.movements.credits_converted_ugx', 16000)
            ->assertJsonPath('data.review.wallet.movements.withdrawn_ugx', 3000)
            ->assertJsonPath('data.review.wallet.movements.withdrawals_pending_ugx', 1000)
            ->assertJsonPath('data.review.wallet.movements.credits_purchased_ugx', 500);
    }

    public function test_admin_can_create_multiple_artists_with_the_same_name_without_slug_collision(): void
    {
        $payload = [
            'name' => 'Same Name Artist',
            'password' => 'password123',
            'role' => 'artist',
            'is_active' => true,
        ];

        $firstResponse = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            ...$payload,
            'email' => 'same-name-1@example.com',
        ]);

        $secondResponse = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            ...$payload,
            'email' => 'same-name-2@example.com',
        ]);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();

        $firstArtist = \App\Models\Artist::whereHas('user', fn ($query) => $query->where('email', 'same-name-1@example.com'))->firstOrFail();
        $secondArtist = \App\Models\Artist::whereHas('user', fn ($query) => $query->where('email', 'same-name-2@example.com'))->firstOrFail();

        $this->assertNotSame($firstArtist->slug, $secondArtist->slug);
    }

    public function test_admin_can_create_event_organizer_profile_without_artist_role(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Event Organizer',
            'email' => 'organizer@example.com',
            'password' => 'password123',
            'role' => 'user',
            'is_event_organizer' => true,
            'organizer_business_name' => 'Tesotunes Live',
            'organizer_support_email' => 'events@example.com',
            'organizer_support_phone' => '+256700000000',
            'organizer_payout_method' => 'mobile_money',
            'organizer_mobile_money_provider' => 'mtn',
            'organizer_mobile_money_number' => '256700000000',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.event_organizer.enabled', true)
            ->assertJsonPath('data.event_organizer.business_name', 'Tesotunes Live');

        $user = User::where('email', 'organizer@example.com')->firstOrFail();

        $this->assertTrue($user->isEventOrganizer());
        $this->assertSame('Tesotunes Live', $user->getEventOrganizerProfile()['business_name']);
    }

    public function test_moderator_can_view_users_but_not_mutate_them(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'moderator'],
            ['display_name' => 'Moderator', 'description' => 'Moderator access', 'is_active' => true, 'priority' => 4]
        );

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator', $this->admin->id);
        $moderator->clearPermissionCache();

        $managedUser = User::factory()->create();
        $managedUser->assignRole('user', $this->admin->id);
        $managedUser->clearPermissionCache();

        $this->actingAs($moderator)
            ->getJson('/api/admin/users')
            ->assertOk();

        $this->actingAs($moderator)
            ->getJson("/api/admin/users/{$managedUser->id}")
            ->assertOk();

        $this->actingAs($moderator)
            ->postJson('/api/admin/users', [
                'name' => 'Blocked Create',
                'email' => 'blocked-create@example.com',
                'password' => 'password123',
                'role' => 'user',
                'is_active' => true,
            ])
            ->assertForbidden();
    }
}
