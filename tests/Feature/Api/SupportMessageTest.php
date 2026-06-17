<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_message_notifies_admins_and_moderators(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN, $admin->id);
        $admin->clearPermissionCache();

        $moderator = User::factory()->create();
        $moderator->assignRole(Role::MODERATOR, $moderator->id);
        $moderator->clearPermissionCache();

        $sender = User::factory()->create();

        $response = $this->actingAs($sender)->postJson('/api/support/messages', [
            'subject' => 'Cannot upload',
            'message' => 'I keep getting an error when uploading my song.',
            'category' => 'bug',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.recipient_count', 2);

        foreach ([$admin, $moderator] as $staff) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $staff->id,
                'type' => 'support_message',
                'category' => 'support',
            ]);
        }

        // The sender must not receive their own support notification.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $sender->id,
            'type' => 'support_message',
        ]);
    }

    public function test_message_is_required(): void
    {
        $sender = User::factory()->create();

        $this->actingAs($sender)
            ->postJson('/api/support/messages', ['message' => ''])
            ->assertStatus(422);
    }

    public function test_guests_cannot_send_support_messages(): void
    {
        $this->postJson('/api/support/messages', [
            'message' => 'Hello team, this is a guest message.',
        ])->assertUnauthorized();
    }
}
