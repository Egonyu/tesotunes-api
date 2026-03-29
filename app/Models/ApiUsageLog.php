<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiUsageLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'method',
        'endpoint',
        'status_code',
        'response_time_ms',
        'ip_address',
        'user_agent',
        'requested_at',
        'request_id',
        'trace_id',
        'session_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'response_time_ms' => 'integer',
        'status_code' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEndpoint($query, string $endpoint)
    {
        return $query->where('endpoint', $endpoint);
    }

    public function scopeMethod($query, string $method)
    {
        return $query->where('method', strtoupper($method));
    }

    public function scopeSince($query, $date)
    {
        return $query->where('requested_at', '>=', $date);
    }

    public function scopeErrors($query)
    {
        return $query->where('status_code', '>=', 400);
    }
}
