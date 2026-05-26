<?php

namespace App\Models;

use App\Enums\KycDocumentStatus;
use App\Enums\KycDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property KycDocumentType $document_type
 * @property string|null $document_number
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property KycDocumentStatus $status
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property int|null $verified_by
 * @property string|null $rejection_reason
 */
class KYCDocument extends Model
{
    use HasFactory;

    protected $table = 'kyc_documents';

    /**
     * @deprecated Use KycDocumentStatus::Pending->value. Kept for backward compatibility.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * @deprecated Use KycDocumentStatus::Verified->value. Kept for backward compatibility.
     *             Pre-2026-05-19 this was the typo `'active'`; normalized via migration.
     */
    public const STATUS_VERIFIED = 'verified';

    /**
     * @deprecated Use KycDocumentStatus::Rejected->value. Kept for backward compatibility.
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * @deprecated Use KycDocumentType::NationalIdFront->value.
     */
    public const TYPE_NATIONAL_ID_FRONT = 'national_id_front';

    /**
     * @deprecated Use KycDocumentType::NationalIdBack->value.
     */
    public const TYPE_NATIONAL_ID_BACK = 'national_id_back';

    /**
     * @deprecated Use KycDocumentType::SelfieWithId->value.
     */
    public const TYPE_SELFIE_WITH_ID = 'selfie_with_id';

    protected $fillable = [
        'user_id',
        'document_type',
        'document_number',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'status',
        'ip_address',
        'verified_at',
        'verified_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => KycDocumentType::class,
            'status' => KycDocumentStatus::class,
            'verified_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', KycDocumentStatus::Verified);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', KycDocumentStatus::Pending);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', KycDocumentStatus::Rejected);
    }
}
