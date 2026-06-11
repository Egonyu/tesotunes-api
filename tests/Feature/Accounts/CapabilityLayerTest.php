<?php

namespace Tests\Feature\Accounts;

use App\Enums\Capability;
use App\Enums\CapabilityStatus;
use App\Models\Artist;
use App\Models\User;
use App\Services\Accounts\CapabilityService;
use App\Services\Kyc\KycService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class CapabilityLayerTest extends TestCase
{
    use CreatesUsersWithRoles, DatabaseTransactions;

    private CapabilityService $capabilities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capabilities = app(CapabilityService::class);
    }

    public function test_apply_then_grant_lifecycle(): void
    {
        $user = User::factory()->create();

        $grant = $this->capabilities->apply($user, Capability::Organizer, ['organization_name' => 'Soroti Live']);
        $this->assertSame(CapabilityStatus::Pending, $grant->status);
        $this->assertFalse($user->hasCapability(Capability::Organizer));

        $this->capabilities->grant($user, Capability::Organizer);
        $this->assertTrue($user->fresh()->hasCapability(Capability::Organizer));
    }

    public function test_kyc_gated_grant_blocks_unverified_users(): void
    {
        $user = User::factory()->create();
        $this->capabilities->apply($user, Capability::Organizer);

        $this->expectException(\DomainException::class);
        $this->capabilities->grant($user, Capability::Organizer, requireKyc: true);
    }

    public function test_kyc_gated_grant_passes_verified_users(): void
    {
        $user = User::factory()->create();
        $admin = $this->createUserWithRole('admin');
        app(KycService::class)->markVerified($user, $admin);

        $grant = $this->capabilities->grant($user->fresh(), Capability::Organizer, requireKyc: true);

        $this->assertSame(CapabilityStatus::Granted, $grant->status);
    }

    public function test_rejected_application_can_reapply(): void
    {
        $user = User::factory()->create();
        $admin = $this->createUserWithRole('admin');

        $grant = $this->capabilities->apply($user, Capability::Seller);
        $this->capabilities->reject($grant, 'Incomplete details', $admin);
        $this->assertSame(CapabilityStatus::Rejected, $grant->fresh()->status);

        $reapplied = $this->capabilities->apply($user, Capability::Seller, ['shop' => 'second try']);
        $this->assertSame(CapabilityStatus::Pending, $reapplied->status);
        $this->assertSame($grant->id, $reapplied->id, 're-application reuses the same grant row');
    }

    public function test_is_event_organizer_reads_grants_with_legacy_json_fallback(): void
    {
        $granted = User::factory()->create();
        $this->capabilities->grant($granted, Capability::Organizer);
        $this->assertTrue($granted->fresh()->isEventOrganizer());

        $legacy = User::factory()->create();
        $legacy->syncEventOrganizerProfile(['enabled' => true]);
        $this->assertTrue($legacy->fresh()->isEventOrganizer());

        $neither = User::factory()->create();
        $this->assertFalse($neither->isEventOrganizer());
    }

    public function test_organizer_self_service_application_endpoint(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/capabilities/organizer/apply', [
            'organization_name' => 'Teso Events Collective',
            'phone' => '0770123456',
            'experience_summary' => 'Ran three community concerts in Soroti over the past two years.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $user->id,
            'capability' => 'organizer',
            'status' => 'pending',
        ]);
    }

    public function test_admin_review_grants_kyc_verified_applicant_and_blocks_unverified(): void
    {
        $admin = $this->createUserWithRole('admin');

        $unverified = User::factory()->create();
        $pendingUnverified = $this->capabilities->apply($unverified, Capability::Organizer);

        $this->actingAs($admin)
            ->postJson("/api/admin/capabilities/{$pendingUnverified->id}/review", ['decision' => 'grant'])
            ->assertUnprocessable();

        $verified = User::factory()->create();
        app(KycService::class)->markVerified($verified, $admin);
        $pendingVerified = $this->capabilities->apply($verified->fresh(), Capability::Organizer);

        $this->actingAs($admin)
            ->postJson("/api/admin/capabilities/{$pendingVerified->id}/review", ['decision' => 'grant'])
            ->assertOk()
            ->assertJsonPath('data.status', 'granted');

        $this->assertTrue($verified->fresh()->hasCapability(Capability::Organizer));
    }

    public function test_capability_posture_endpoint_lists_all_capabilities(): void
    {
        $user = User::factory()->create();
        $this->capabilities->grant($user, Capability::Promoter);

        $response = $this->actingAs($user)->getJson('/api/capabilities');

        $response->assertOk();
        $posture = collect($response->json('data'))->keyBy('capability');
        $this->assertSame('granted', $posture['promoter']['status']);
        $this->assertSame('none', $posture['label']['status']);
        $this->assertCount(5, $posture);
    }

    public function test_backfill_seeds_grants_from_existing_sources_idempotently(): void
    {
        $artistUser = User::factory()->create();
        Artist::factory()->create(['user_id' => $artistUser->id, 'status' => 'approved']);

        $legacyOrganizer = User::factory()->create();
        $legacyOrganizer->syncEventOrganizerProfile(['enabled' => true]);

        Artisan::call('capabilities:backfill');
        Artisan::call('capabilities:backfill');

        $this->assertTrue($artistUser->fresh()->hasCapability(Capability::Artist));
        $this->assertTrue(
            $legacyOrganizer->fresh()->capabilities()->where('capability', Capability::Organizer)->granted()->exists()
        );
        $this->assertSame(1, $artistUser->capabilities()->count());
    }
}
