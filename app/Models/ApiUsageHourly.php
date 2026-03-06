<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiUsageHourly extends Model
{
    public $timestamps = false;

    protected $table = 'api_usage_hourly';

    protected $fillable = [
        'endpoint',
        'method',
        'date',
        'hour',
        'total_requests',
        'success_count',
        'client_error_count',
        'server_error_count',
        'avg_response_ms',
        'max_response_ms',
        'unique_users',
    ];

    protected $casts = [
        'date' => 'date',
        'hour' => 'integer',
        'total_requests' => 'integer',
        'success_count' => 'integer',
        'client_error_count' => 'integer',
        'server_error_count' => 'integer',
        'avg_response_ms' => 'integer',
        'max_response_ms' => 'integer',
        'unique_users' => 'integer',
    ];

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
