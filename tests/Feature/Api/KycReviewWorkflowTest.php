<?php

namespace Tests\Feature\Api;

use App\Enums\KycDocumentStatus;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\KYCDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(
            ['name' => 'moderator'],
            [
                'display_name' => 'Moderator',
                'description' => 'Content and identity reviewer',
                'is_active' => true,
                'priority' => 50,
            ],
        );

        $this->moderator = User::factory()->create();
        $this->moderator->assignRole('moderator', $this->moderator->id);
        $this->moderator->clearPermissionCache();

        Storage::fake('private');
    }

    public function test_moderator_can_open_private_documents_but_regular_user_cannot(): void
    {
        $applicant = $this->pendingApplicant();
        $document = $this->createDocument($applicant, KycDocumentType::NationalIdFront, 'front.jpg');
        Storage::disk('private')->put($document->file_path, 'private-id-content');

        $queue = $this->actingAs($this->moderator)
            ->getJson('/api/admin/kyc/pending')
            ->assertOk()
            ->assertJsonPath('data.0.documents.0.review_url', "/api/admin/kyc/documents/{$document->id}");

        $reviewUrl = $queue->json('data.0.documents.0.review_url');

        $documentResponse = $this->actingAs($this->moderator)
            ->get($reviewUrl)
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=0, no-store, private');

        $this->assertSame('private-id-content', $documentResponse->streamedContent());

        $regularUser = User::factory()->create();
        $this->actingAs($regularUser)->get($reviewUrl)->assertForbidden();
    }

    public function test_moderator_can_approve_a_complete_pending_submission(): void
    {
        $applicant = $this->pendingApplicant();
        foreach (KycDocumentType::required() as $type) {
            $this->createDocument($applicant, $type, "{$type->value}.jpg");
        }

        $this->actingAs($this->moderator)
            ->postJson("/api/admin/kyc/users/{$applicant->id}/review", [
                'decision' => 'approve',
                'notes' => 'ID details and selfie match.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', KycStatus::Verified->value);

        $this->assertSame(KycStatus::Verified, $applicant->fresh()->kyc_status);
        $this->assertSame(
            3,
            $applicant->kycDocuments()->where('status', KycDocumentStatus::Verified)->count(),
        );
        $this->assertDatabaseHas('notifications', [
            'user_id' => $applicant->id,
            'type' => 'kyc_approved',
            'action_url' => '/verify',
        ]);
    }

    public function test_incomplete_submission_cannot_be_approved(): void
    {
        $applicant = $this->pendingApplicant();
        $this->createDocument($applicant, KycDocumentType::NationalIdFront, 'front.jpg');

        $this->actingAs($this->moderator)
            ->postJson("/api/admin/kyc/users/{$applicant->id}/review", [
                'decision' => 'approve',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');

        $this->assertSame(KycStatus::PendingReview, $applicant->fresh()->kyc_status);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $applicant->id,
            'type' => 'kyc_approved',
        ]);
    }

    public function test_rejection_notifies_user_and_allows_resubmission(): void
    {
        $applicant = $this->pendingApplicant();
        foreach (KycDocumentType::required() as $type) {
            $this->createDocument($applicant, $type, "{$type->value}.jpg");
        }

        $reason = 'The national ID image is blurry. Upload a clear image with all corners visible.';

        $this->actingAs($this->moderator)
            ->postJson("/api/admin/kyc/users/{$applicant->id}/review", [
                'decision' => 'reject',
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', KycStatus::Rejected->value)
            ->assertJsonPath('data.can_submit_documents', true)
            ->assertJsonPath('data.rejection_reason', $reason);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $applicant->id,
            'type' => 'kyc_resubmission_required',
            'message' => $reason,
            'action_url' => '/verify',
        ]);

        $this->actingAs($applicant->fresh())
            ->post('/api/kyc/documents', [
                'document_type' => KycDocumentType::NationalIdFront->value,
                'file' => UploadedFile::fake()->create('clear-front.jpg', 100, 'image/jpeg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.kyc_status', KycStatus::PendingReview->value);

        foreach ([KycDocumentType::NationalIdBack, KycDocumentType::SelfieWithId] as $type) {
            $this->actingAs($applicant->fresh())
                ->post('/api/kyc/documents', [
                    'document_type' => $type->value,
                    'file' => UploadedFile::fake()->create("replacement-{$type->value}.jpg", 100, 'image/jpeg'),
                ])
                ->assertCreated();
        }

        $applicant->refresh();
        $this->assertSame(KycStatus::PendingReview, $applicant->kyc_status);
        $this->assertNull($applicant->kyc_rejection_reason);
        $this->assertSame(
            3,
            $applicant->kycDocuments()->where('status', KycDocumentStatus::Pending)->count(),
        );
    }

    private function pendingApplicant(): User
    {
        return User::factory()->create([
            'kyc_status' => KycStatus::PendingReview->value,
            'kyc_submitted_at' => now()->subMinute(),
        ]);
    }

    private function createDocument(User $user, KycDocumentType $type, string $filename): KYCDocument
    {
        return KYCDocument::query()->create([
            'user_id' => $user->id,
            'document_type' => $type,
            'file_path' => "kyc/{$user->id}/{$filename}",
            'file_name' => $filename,
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'status' => KycDocumentStatus::Pending,
        ]);
    }
}
