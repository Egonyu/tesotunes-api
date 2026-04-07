<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistCollaborator extends Model
{
    use HasFactory;

    protected $table = 'playlist_collaborators';

    protected $fillable = [
        'playlist_id',
        'user_id',
        'role',
        'invited_by',
        'status',
        'approved_at',
        'joined_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    public const ROLE_ADMIN = 'admin';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_VIEWER = 'viewer';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_INVITED = 'invited';

    public const VALID_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_EDITOR,
        self::ROLE_VIEWER,
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function canEdit(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_EDITOR], true)
            && $this->status === self::STATUS_ACCEPTED;
    }

    public function canManageCollaborators(): bool
    {
        return $this->role === self::ROLE_ADMIN && $this->status === self::STATUS_ACCEPTED;
    }
}
