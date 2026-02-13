<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventLocation extends Model
{
    use HasFactory;

    protected $table = 'event_locations';

    protected $fillable = [
        'uuid',
        'name',
        'address',
        'city',
        'state_province',
        'country',
        'postal_code',
        'latitude',
        'longitude',
        'capacity',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'capacity' => 'integer',
    ];

    public function events()
    {
        return $this->hasMany(Event::class, 'event_location_id');
    }
}
