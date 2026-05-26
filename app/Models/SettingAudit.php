<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'setting_key',
        'group',
        'audit_category',
        'old_value',
        'new_value',
        'old_version',
        'new_version',
        'actor_user_id',
        'actor_ip',
        'actor_role',
        'reason',
        'was_secret',
        'reverted_from',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_version' => 'integer',
            'new_version' => 'integer',
            'actor_user_id' => 'integer',
            'was_secret' => 'boolean',
            'reverted_from' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function revertedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverted_from');
    }
}
