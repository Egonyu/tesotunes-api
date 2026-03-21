<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventStaffMember extends Model
{
    use HasFactory;

    public const ROLE_FINANCE = 'finance';

    public const ROLE_CHECK_IN = 'check_in_staff';

    public const ROLE_PROMOTER = 'promoter';

    public const ROLE_ANALYST = 'analyst';

    public const ASSIGNABLE_ROLES = [
        self::ROLE_FINANCE,
        self::ROLE_CHECK_IN,
        self::ROLE_PROMOTER,
        self::ROLE_ANALYST,
    ];

    protected $fillable = [
        'uuid',
        'event_id',
        'user_id',
        'invited_by_user_id',
        'role',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
