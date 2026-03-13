<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KYCDocument extends Model
{
    use HasFactory;

    protected $table = 'kyc_documents';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'active';

    public const STATUS_REJECTED = 'rejected';

    public const TYPE_NATIONAL_ID_FRONT = 'national_id_front';

    public const TYPE_NATIONAL_ID_BACK = 'national_id_back';

    public const TYPE_SELFIE_WITH_ID = 'selfie_with_id';

    protected $fillable = [
        'user_id',
        'document_type',
        'document_number',
        'document_front',
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

    protected $casts = [
        'verified_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
