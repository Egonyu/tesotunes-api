<?php

namespace App\Modules\Sacco\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SaccoAuditLog extends Model
{
    /**
     * Use the existing audit_logs table
     */
    protected $table = 'audit_logs';

    /**
     * Fillable fields matching actual audit_logs table:
     * user_id, event, auditable_type, auditable_id, data, ip_address
     */
    protected $fillable = [
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'data',
        'ip_address',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    /**
     * Get the user who performed the action
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the auditable model
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
