<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Music Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how music files and related assets are stored and managed.
    | Supports local storage and cloud providers like DigitalOcean Spaces.
    |
    */

    'storage' => [
        // Primary storage driver: 'local' or 'digitalocean'
        'primary_driver' => env('MUSIC_STORAGE_DRIVER', 'local'),

        // Backup storage driver (optional)
        'backup_driver' => env('MUSIC_BACKUP_STORAGE_DRIVER', null),

        // Storage paths
        'paths' => [
            'uploads' => 'music/uploads',
            'processed' => 'music/processed',
            'artwork' => 'artwork',
            'temp' => 'music/temp',
        ],

        // File size limits (in bytes)
        'limits' => [
            'max_audio_size' => env('MAX_AUDIO_FILE_SIZE', 50 * 1024 * 1024), // 50MB
            'max_artwork_size' => env('MAX_ARTWORK_FILE_SIZE', 10 * 1024 * 1024), // 10MB
        ],

        // Allowed file formats
        'allowed_formats' => [
            'audio' => ['mp3', 'wav', 'flac', 'aac', 'm4a'],
            'artwork' => ['jpg', 'jpeg', 'png', 'webp'],
        ],

        // MIME types
        'allowed_mime_types' => [
            'audio' => [
                'audio/mpeg',
                'audio/wav',
                'audio/x-wav',
                'audio/flac',
                'audio/x-flac',
                'audio/aac',
                'audio/mp4',
                'audio/x-m4a',
            ],
            'artwork' => [
                'image/jpeg',
                'image/png',
                'image/jpg',
                'image/webp',
            ],
        ],

        // CDN and streaming configuration
        'cdn' => [
            'enabled' => env('MUSIC_CDN_ENABLED', false),
            'base_url' => env('MUSIC_CDN_BASE_URL'),
        ],

        // Storage cleanup settings
        'cleanup' => [
            'delete_temp_files_after' => 24, // hours
            'delete_failed_uploads_after' => 7, // days
        ],

        // Performance settings
        'performance' => [
            'chunk_size' => env('MUSIC_UPLOAD_CHUNK_SIZE', 1024 * 1024), // 1MB chunks
            'concurrent_uploads' => env('MUSIC_CONCURRENT_UPLOADS', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for audio file processing, metadata extraction, and quality checks.
    |
    */

    'processing' => [
        // Audio quality standards
        'quality_standards' => [
            'minimum_bitrate' => 128, // kbps
            'preferred_bitrate' => 320, // kbps
            'minimum_sample_rate' => 44100, // Hz
            'preferred_sample_rate' => 44100, // Hz
        ],

        // Metadata extraction
        'metadata' => [
            'extract_embedded_artwork' => true,
            'extract_lyrics' => true,
            'extract_technical_info' => true,
        ],

        // Processing timeouts
        'timeouts' => [
            'metadata_extraction' => 30, // seconds
            'quality_analysis' => 60, // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ISRC Configuration
    |--------------------------------------------------------------------------
    |
    | International Standard Recording Code configuration for Uganda.
    | Official ISRC Prefix Code: UG-A65 (Uniform Golf - Alpha Six Five)
    | Allocated to: Egonyu Daniel / TesoTunes
    |
    | ISRC Format: CC-XXX-YY-NNNNN
    | - CC: Country Code (UG for Uganda)
    | - XXX: Registrant Code (A65)
    | - YY: Year of Reference (last 2 digits)
    | - NNNNN: Designation Code (5-digit unique per year)
    |
    */

    'isrc' => [
        'country_code' => 'UG', // Uganda
        'registrant_code' => env('ISRC_REGISTRANT_CODE', 'A65'), // Official prefix
        'default_registrant_prefix' => env('ISRC_REGISTRANT_PREFIX', 'A65'),
        'auto_generate' => env('ISRC_AUTO_GENERATE', true),
        'registrant_name' => env('ISRC_REGISTRANT_NAME', 'Egonyu Daniel / TesoTunes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Distribution Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for music distribution to streaming platforms.
    |
    */

    'distribution' => [
        'auto_submit' => env('MUSIC_AUTO_SUBMIT_DISTRIBUTION', false),
        'platforms' => [
            'spotify' => env('DISTRIBUTE_TO_SPOTIFY', true),
            'apple_music' => env('DISTRIBUTE_TO_APPLE_MUSIC', true),
            'youtube_music' => env('DISTRIBUTE_TO_YOUTUBE_MUSIC', true),
            'boomplay' => env('DISTRIBUTE_TO_BOOMPLAY', true), // Popular in Africa
            'audiomack' => env('DISTRIBUTE_TO_AUDIOMACK', true), // Popular in Africa
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rights Management
    |--------------------------------------------------------------------------
    |
    | Configuration for music rights and royalty management.
    |
    */

    'rights' => [
        'default_ownership_percentage' => 100,
        'require_splits_approval' => true,
        'auto_register_with_umro' => env('AUTO_REGISTER_UMRO', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ugandan Music Industry Settings
    |--------------------------------------------------------------------------
    |
    | Specific configurations for the Ugandan music market.
    |
    */

    'uganda' => [
        'local_languages' => [
            'Luganda',
            'Swahili',
            'Luo',
            'Runyankole',
            'Rutooro',
            'Lugbara',
            'Ateso',
            'Runyoro',
            'Lusoga',
        ],

        'popular_genres' => [
            'Kadongo Kamu',
            'Lugaflow',
            'Afrobeats',
            'Dancehall',
            'Gospel',
            'Kidandali',
            'Traditional',
        ],

        'content_guidelines' => [
            'require_explicit_content_warning' => true,
            'require_language_declaration' => true,
            'require_cultural_context' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming and Playback
    |--------------------------------------------------------------------------
    |
    | Configuration for music streaming and playback features.
    |
    */

    'streaming' => [
        'chunk_size' => 1024 * 8, // 8KB chunks for streaming
        'cache_duration' => 3600, // 1 hour cache for streaming URLs
        'allowed_origins' => ['*'], // CORS origins for streaming
        'rate_limiting' => [
            'max_requests_per_minute' => 60,
            'max_downloads_per_day' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Moderation
    |--------------------------------------------------------------------------
    |
    | Settings for automated and manual content moderation.
    |
    */

    'moderation' => [
        'auto_scan_explicit_content' => env('AUTO_SCAN_EXPLICIT_CONTENT', true),
        'require_manual_review' => env('REQUIRE_MANUAL_REVIEW', false),
        'flagged_content_action' => 'quarantine', // quarantine, reject, or approve_with_warning
    ],
];