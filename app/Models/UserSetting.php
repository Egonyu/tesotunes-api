<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $table = 'user_settings';

    protected $fillable = [
        'user_id',
        'email_notifications',
        'push_notifications',
        'language',
        'theme',
        'autoplay',
        'audio_quality',
        'explicit_content',
        'show_listening_activity',
        'private_profile',
        'compact_mode',
        'audio_quality_preference',
        'download_quality',
        'streaming_quality_mobile',
        'streaming_quality_wifi',
        'autoplay_enabled',
        'volume_level',
        'profile_public',
        'show_activity',
        'allow_followers',
        'allow_messages',
        'notification_preferences',
        'privacy_settings',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'push_notifications' => 'boolean',
        'autoplay' => 'boolean',
        'explicit_content' => 'boolean',
        'show_listening_activity' => 'boolean',
        'private_profile' => 'boolean',
        'compact_mode' => 'boolean',
        'autoplay_enabled' => 'boolean',
        'profile_public' => 'boolean',
        'show_activity' => 'boolean',
        'allow_followers' => 'boolean',
        'allow_messages' => 'boolean',
        'notification_preferences' => 'array',
        'privacy_settings' => 'array',
    ];

    /**
     * Create default settings for a user.
     * All columns have DB defaults, so we only need the user_id.
     */
    public static function createDefault(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id]
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
