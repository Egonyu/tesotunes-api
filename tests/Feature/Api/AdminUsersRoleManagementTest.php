<?php

namespace Tests\Feature\Api;

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

        Role::factory()->user()->create();
        Role::factory()->artist()->create();
        Role::factory()->admin()->create();

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
        $this->assertCount(0, $response->json('data'));
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
}
