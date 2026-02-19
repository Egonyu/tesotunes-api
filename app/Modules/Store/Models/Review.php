<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $table = 'store_reviews';

    protected $fillable = [
        'store_id',
        'user_id',
        'rating',
        'comment',
    ];
}
