<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'display_name',
        'first_name',
        'last_name',
        'bio',
        'avatar',
        'banner',
        'gender',
        'country',
        'city',
        'timezone',
        'date_of_birth',
        'language',
        'instagram_url',
        'twitter_url',
        'facebook_url',
        'youtube_url',
        'tiktok_url',
        'profile_completion_percentage',
        'profile_steps_completed',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'profile_completion_percentage' => 'integer',
        'profile_steps_completed' => 'array',
    ];

    public static function createDefault(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id],
            [
                'display_name' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'bio' => $user->bio,
                'avatar' => $user->avatar,
                'banner' => $user->banner,
                'gender' => $user->gender,
                'country' => $user->country,
                'city' => $user->city,
                'timezone' => $user->timezone,
                'date_of_birth' => $user->date_of_birth,
                'language' => $user->language,
                'instagram_url' => $user->instagram_url,
                'twitter_url' => $user->twitter_url,
                'facebook_url' => $user->facebook_url,
                'youtube_url' => $user->youtube_url,
                'tiktok_url' => $user->tiktok_url,
                'profile_completion_percentage' => $user->profile_completion_percentage ?? 0,
                'profile_steps_completed' => $user->profile_steps_completed,
            ]
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
