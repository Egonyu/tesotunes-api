<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\ArtistProfile;
use App\Models\Genre;
use App\Models\KYCDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtistApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_status_returns_none_when_user_has_no_application(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/artist/application-status')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'none',
                    'is_artist' => false,
                ],
            ]);
    }

    public function test_application_status_returns_pending_for_pending_artist_application(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'is_verified' => false,
            'can_upload' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/artist/application-status')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'pending',
                    'is_artist' => false,
                    'artist' => [
                        'id' => $artist->id,
                        'stage_name' => $artist->stage_name,
                        'slug' => $artist->slug,
                    ],
                ],
            ]);
    }

    public function test_submitting_artist_application_creates_pending_records_and_syncs_normalized_models(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'application_status' => null,
            'phone' => null,
            'nin_number' => null,
        ]);
        $genre = $this->createGenre('frontend-contract-genre');

        $payload = [
            'stage_name' => 'Frontend Contract Artist',
            'bio' => str_repeat('Artist bio ', 8),
            'primary_genre' => $genre->id,
            'full_name' => 'Frontend Contract Artist',
            'phone' => '+256700000001',
            'payout_method' => 'mtn_momo',
            'mobile_money_number' => '+256700000001',
            'mobile_money_provider' => 'mtn',
            'country' => 'UG',
            'city' => 'Soroti',
            'website_url' => 'https://example.com/artist',
            'nin_number' => 'CFA-123456',
            'terms_accepted' => true,
            'artist_agreement_accepted' => true,
            'social_links' => [
                'instagram' => 'https://instagram.com/frontendartist',
            ],
            'national_id_front' => UploadedFile::fake()->image('front.jpg'),
            'national_id_back' => UploadedFile::fake()->image('back.jpg'),
            'selfie_with_id' => UploadedFile::fake()->image('selfie.jpg'),
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/artist/apply', $payload);

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'data' => [
                    'application_status' => 'pending',
                    'stage_name' => 'Frontend Contract Artist',
                ],
            ]);

        $artist = Artist::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('pending', $artist->status);
        $this->assertFalse((bool) $artist->can_upload);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'application_status' => 'pending',
            'phone' => '+256700000001',
            'nin_number' => 'CFA-123456',
            'mobile_money_number' => '+256700000001',
            'mobile_money_provider' => 'mtn',
        ]);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'display_name' => 'Frontend Contract Artist',
            'city' => 'Soroti',
        ]);

        $artistProfile = ArtistProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($artist->id, $artistProfile->artist_id);
        // Canonical KYC state is on the user (3-axis model)
        $user->refresh();
        $this->assertSame(\App\Enums\KycStatus::PendingReview, $user->kyc_status);
        $this->assertSame('mobile_money', $artistProfile->payout_method);
        $this->assertSame('+256700000001', $artistProfile->mobile_money_number);

        $this->assertDatabaseCount('kyc_documents', 3);
        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $user->id,
            'document_type' => KYCDocument::TYPE_NATIONAL_ID_FRONT,
            'status' => KYCDocument::STATUS_PENDING,
        ]);
    }

    public function test_rejected_artist_can_reapply_and_existing_application_is_reopened(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'application_status' => 'rejected',
            'rejection_reason' => 'Old rejection reason',
        ]);
        $genre = $this->createGenre('reapplied-genre');

        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => \App\Enums\ArtistStatus::Rejected->value,
            'is_verified' => false,
            'rejection_reason' => 'Old rejection reason',
            'can_upload' => false,
        ]);

        ArtistProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
            ]
        );

        $user->forceFill(['kyc_status' => \App\Enums\KycStatus::Rejected->value])->save();

        $response = $this->actingAs($user, 'sanctum')->post('/api/artist/apply', [
            'stage_name' => 'Reapplied Artist',
            'bio' => str_repeat('Updated artist bio ', 6),
            'primary_genre' => $genre->id,
            'full_name' => 'Reapplied Artist',
            'phone' => '+256700000003',
            'payout_method' => 'mtn_momo',
            'mobile_money_number' => '+256700000003',
            'mobile_money_provider' => 'mtn',
            'terms_accepted' => true,
            'artist_agreement_accepted' => true,
            'national_id_front' => UploadedFile::fake()->image('front.jpg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.artist_id', $artist->id);

        $this->assertDatabaseHas('artists', [
            'id' => $artist->id,
            'status' => \App\Enums\ArtistStatus::Pending->value,
            'rejection_reason' => null,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'application_status' => 'pending',
            'rejection_reason' => null,
            'kyc_status' => \App\Enums\KycStatus::PendingReview->value,
        ]);

        $this->assertDatabaseHas('artist_profiles', [
            'user_id' => $user->id,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_submitting_application_with_zengapay_defaults_mobile_number_from_phone(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'application_status' => null,
        ]);
        $genre = $this->createGenre('zengapay-genre');

        $phone = '+256700000777';

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/artist/apply', [
                'stage_name' => 'Zenga Artist',
                'bio' => str_repeat('Zenga artist bio ', 6),
                'primary_genre' => $genre->id,
                'full_name' => 'Zenga Artist',
                'phone' => $phone,
                'payout_method' => 'zengapay',
                'terms_accepted' => true,
                'artist_agreement_accepted' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'mobile_money_number' => $phone,
            'application_status' => 'pending',
        ]);
    }

    public function test_submitting_application_with_zengapay_provider_payload_succeeds(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'application_status' => null,
        ]);
        $genre = $this->createGenre('zengapay-provider-genre');

        $phone = '+256700000778';

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/artist/apply', [
                'stage_name' => 'Zenga Provider Artist',
                'bio' => str_repeat('Zenga provider artist bio ', 6),
                'primary_genre' => $genre->id,
                'full_name' => 'Zenga Provider Artist',
                'phone' => $phone,
                'payout_method' => 'zengapay',
                'mobile_money_provider' => 'zengapay',
                'terms_accepted' => true,
                'artist_agreement_accepted' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'mobile_money_provider' => 'zengapay',
            'mobile_money_number' => $phone,
            'application_status' => 'pending',
        ]);
    }

    public function test_submitting_application_with_payment_option_fallback_defaults_to_zengapay(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'application_status' => null,
        ]);
        $genre = $this->createGenre('payment-option-fallback-genre');

        $phone = '+256700000779';

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/artist/apply', [
                'stage_name' => 'Payment Option Artist',
                'bio' => str_repeat('Payment option artist bio ', 6),
                'primary_genre' => $genre->id,
                'full_name' => 'Payment Option Artist',
                'phone' => $phone,
                'payment_option' => 'zengapay',
                'terms_accepted' => true,
                'artist_agreement_accepted' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'mobile_money_provider' => 'zengapay',
            'mobile_money_number' => $phone,
            'application_status' => 'pending',
        ]);
    }

    protected function createGenre(string $slug): Genre
    {
        return Genre::create([
            'uuid' => (string) \Str::uuid(),
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => 'Test genre',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
