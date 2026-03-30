<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoleTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'description',
        'base_role_name',
        'role_name',
        'display_name',
        'role_description',
        'priority',
        'is_active',
        'permissions',
        'is_system',
        'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];
}
