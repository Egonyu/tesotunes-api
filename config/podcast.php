<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Podcast Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the LineOne Music Platform Podcast Module.
    | This module can be completely disabled via PODCAST_ENABLED=false.
    |
    */

    'enabled' => env('PODCAST_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    */

    'storage' => [
        // Primary storage driver: 'local' or 'digitalocean'
        'primary_driver' => env('PODCAST_STORAGE_DRIVER', 'digitalocean'),
        
        // Backup storage driver (optional)
        'backup_driver' => env('PODCAST_BACKUP_STORAGE_DRIVER', 'local'),
        
        // Storage paths
        'paths' => [
            'uploads' => 'podcasts/uploads',
            'processed' => 'podcasts/processed',
            'artwork' => 'podcasts/artwork',
            'transcripts' => 'podcasts/transcripts',
            'temp' => 'podcasts/temp',
        ],
        
        // File size limits (in bytes)
        'limits' => [
            'max_episode_size' => env('MAX_PODCAST_EPISODE_SIZE', 200 * 1024 * 1024), // 200MB
            'max_artwork_size' => env('MAX_PODCAST_ARTWORK_SIZE', 10 * 1024 * 1024), // 10MB
        ],
        
        // Allowed file formats
        'allowed_formats' => [
            'audio' => ['mp3', 'wav', 'm4a', 'aac'],
            'artwork' => ['jpg', 'jpeg', 'png'],
        ],
        
        // MIME types
        'allowed_mime_types' => [
            'audio' => [
                'audio/mpeg',
                'audio/wav',
                'audio/x-wav',
                'audio/mp4',
                'audio/x-m4a',
                'audio/aac',
            ],
            'artwork' => [
                'image/jpeg',
                'image/png',
                'image/jpg',
            ],
        ],
        
        // CDN and streaming configuration
        'cdn' => [
            'enabled' => env('PODCAST_CDN_ENABLED', true),
            'base_url' => env('PODCAST_CDN_BASE_URL'),
        ],
        
        // Storage cleanup settings
        'cleanup' => [
            'delete_temp_files_after' => 24, // hours
            'delete_failed_uploads_after' => 7, // days
        ],
        
        // Performance settings
        'performance' => [
            'chunk_size' => env('PODCAST_UPLOAD_CHUNK_SIZE', 1024 * 1024), // 1MB chunks
            'concurrent_uploads' => env('PODCAST_CONCURRENT_UPLOADS', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Processing Configuration
    |--------------------------------------------------------------------------
    */

    'processing' => [
        // Audio quality standards
        'quality_standards' => [
            'minimum_bitrate' => 64, // kbps
            'preferred_bitrate' => 128, // kbps
            'high_quality_bitrate' => 320, // kbps
            'minimum_sample_rate' => 22050, // Hz
            'preferred_sample_rate' => 44100, // Hz
        ],
        
        // Auto-transcode to multiple qualities
        'auto_transcode' => env('PODCAST_AUTO_TRANSCODE', true),
        
        'quality_levels' => [
            'high' => ['bitrate' => 320, 'sample_rate' => 48000], // Premium quality
            'medium' => ['bitrate' => 128, 'sample_rate' => 44100], // Standard quality
            'low' => ['bitrate' => 64, 'sample_rate' => 22050], // Mobile/Low-bandwidth
        ],
        
        // Audio normalization
        'auto_normalize_audio' => env('PODCAST_AUTO_NORMALIZE', true),
        'auto_generate_waveform' => env('PODCAST_AUTO_WAVEFORM', true),
        
        // Transcription settings
        'transcription' => [
            'enabled' => env('PODCAST_TRANSCRIPTION_ENABLED', false),
            'provider' => env('PODCAST_TRANSCRIPTION_PROVIDER', 'whisper'), // whisper, deepgram, rev
            'auto_transcribe' => env('PODCAST_AUTO_TRANSCRIBE', false),
            'languages' => ['en', 'sw', 'lg'], // English, Swahili, Luganda
        ],
        
        // Metadata extraction
        'metadata' => [
            'extract_embedded_artwork' => true,
            'extract_technical_info' => true,
        ],
        
        // Processing timeouts
        'timeouts' => [
            'metadata_extraction' => 30, // seconds
            'transcoding' => 600, // 10 minutes
            'transcription' => 1800, // 30 minutes
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RSS Feed Configuration
    |--------------------------------------------------------------------------
    */

    'rss' => [
        'base_url' => env('PODCAST_RSS_BASE_URL', env('APP_URL') . '/podcast-rss'),
        'ttl' => env('PODCAST_RSS_TTL', 60), // Cache minutes
        'generator' => 'LineOne Music Platform',
        
        'apple_podcasts' => [
            'submission_enabled' => env('PODCAST_APPLE_SUBMISSION', true),
            'category_mapping' => true,
        ],
        
        'spotify' => [
            'submission_enabled' => env('PODCAST_SPOTIFY_SUBMISSION', true),
            'api_key' => env('SPOTIFY_PODCAST_API_KEY'),
        ],
        
        'google_podcasts' => [
            'submission_enabled' => env('PODCAST_GOOGLE_SUBMISSION', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Freemium Model Configuration
    |--------------------------------------------------------------------------
    */

    'freemium' => [
        'free_episode_limit_per_month' => env('PODCAST_FREE_EPISODE_LIMIT', 5),
        'free_download_limit_per_day' => env('PODCAST_FREE_DOWNLOAD_LIMIT', 3),
        'premium_price_monthly' => env('PODCAST_PREMIUM_PRICE', 15000), // UGX
        
        'premium_features' => [
            'unlimited_episodes' => true,
            'high_quality_audio' => true,
            'offline_downloads' => true,
            'ad_free_experience' => true,
            'early_access' => true,
            'bonus_content' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monetization Configuration
    |--------------------------------------------------------------------------
    */

    'monetization' => [
        'sponsorship_enabled' => env('PODCAST_SPONSORSHIP_ENABLED', true),
        'dynamic_ad_insertion' => env('PODCAST_DYNAMIC_ADS', false),
        
        'revenue_sharing' => [
            'platform_cut_percentage' => 30,
            'creator_cut_percentage' => 70,
        ],
        
        'payout' => [
            'minimum_threshold' => 50000, // UGX
            'payout_schedule' => 'monthly', // weekly, monthly
            'payout_day' => 1, // 1st of month
        ],
        
        'listener_support' => [
            'enabled' => env('PODCAST_LISTENER_SUPPORT', true),
            'min_tip_amount' => 1000, // UGX
            'suggested_amounts' => [5000, 10000, 20000, 50000],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics & Tracking Configuration
    |--------------------------------------------------------------------------
    */

    'analytics' => [
        'track_listens' => true,
        'track_downloads' => true,
        'track_completion_rate' => true,
        'track_skip_rate' => true,
        'track_device_types' => true,
        'track_geographic_data' => true,
        
        // IAB Podcast Measurement Standards compliance
        'iab_compliant' => env('PODCAST_IAB_COMPLIANT', true),
        
        'retention_days' => env('PODCAST_ANALYTICS_RETENTION', 730), // 2 years
    ],

    /*
    |--------------------------------------------------------------------------
    | Distribution Platforms Configuration
    |--------------------------------------------------------------------------
    */

    'distribution' => [
        'platforms' => [
            'apple_podcasts' => [
                'enabled' => env('DISTRIBUTE_PODCAST_APPLE', true),
                'auto_submit' => false,
            ],
            'spotify' => [
                'enabled' => env('DISTRIBUTE_PODCAST_SPOTIFY', true),
                'auto_submit' => false,
            ],
            'google_podcasts' => [
                'enabled' => env('DISTRIBUTE_PODCAST_GOOGLE', true),
                'auto_submit' => false,
            ],
            'youtube_music' => [
                'enabled' => env('DISTRIBUTE_PODCAST_YOUTUBE', true),
                'auto_submit' => false,
            ],
            'amazon_music' => [
                'enabled' => env('DISTRIBUTE_PODCAST_AMAZON', false),
                'auto_submit' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Moderation Configuration
    |--------------------------------------------------------------------------
    */

    'moderation' => [
        'require_manual_review' => env('PODCAST_REQUIRE_REVIEW', true),
        'auto_scan_explicit_content' => env('PODCAST_AUTO_SCAN_EXPLICIT', true),
        'profanity_filter' => env('PODCAST_PROFANITY_FILTER', false),
        
        'approval_workflow' => [
            'first_episode_requires_admin' => true,
            'subsequent_episodes_auto_approve' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling & Publishing Configuration
    |--------------------------------------------------------------------------
    */

    'publishing' => [
        'allow_scheduling' => env('PODCAST_ALLOW_SCHEDULING', true),
        'max_scheduled_episodes' => env('PODCAST_MAX_SCHEDULED', 20),
        'auto_publish_scheduled' => true,
        'timezone' => 'Africa/Kampala',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile & Performance Configuration
    |--------------------------------------------------------------------------
    */

    'mobile' => [
        'adaptive_bitrate' => env('PODCAST_ADAPTIVE_BITRATE', true),
        'offline_mode_enabled' => true,
        'cache_episodes_locally' => true,
        'low_bandwidth_mode' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications Configuration
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        'new_episode_notification' => true,
        'subscription_notification' => true,
        'sponsor_notification' => true,
        'milestone_notification' => true,
        
        'channels' => ['database', 'mail', 'push'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits & Rate Limiting Configuration
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_podcasts_per_user' => env('PODCAST_MAX_PER_USER', 10),
        'max_episodes_per_podcast' => env('PODCAST_MAX_EPISODES', 500),
        'max_collaborators_per_podcast' => env('PODCAST_MAX_COLLABORATORS', 5),
        
        'rate_limiting' => [
            'streams_per_minute' => 30,
            'downloads_per_day_free' => 10, // Free users
            'downloads_per_day_premium' => 100, // Premium users
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags Configuration
    |--------------------------------------------------------------------------
    */

    'features' => [
        'video_podcasts' => env('PODCAST_VIDEO_ENABLED', false),
        'live_streaming' => env('PODCAST_LIVE_ENABLED', false),
        'chapters' => env('PODCAST_CHAPTERS_ENABLED', true),
        'transcripts' => env('PODCAST_TRANSCRIPTS_ENABLED', false),
        'clipping' => env('PODCAST_CLIPPING_ENABLED', false),
        'comments' => env('PODCAST_COMMENTS_ENABLED', true),
        'ratings' => env('PODCAST_RATINGS_ENABLED', true),
    ],

];
