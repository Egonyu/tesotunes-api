<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $table = 'user_settings';
    
    protected $fillable = [
        'user_id',
        'key',
        'value',
    ];
    
    protected $casts = [
        'value' => 'array',
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
