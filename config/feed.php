<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feed Algorithm Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the intelligent feed ranking and caching system.
    | These values control how content is prioritized and displayed in the
    | user's personalized activity feed.
    |
    */

    // Cache Configuration
    'cache' => [
        'enabled' => env('FEED_CACHE_ENABLED', true),
        'driver' => env('FEED_CACHE_DRIVER', 'redis'), // KeyDB-compatible
        'ttl' => [
            'feed' => env('FEED_CACHE_TTL', 300),                    // 5 minutes
            'activities' => env('FEED_ACTIVITIES_CACHE_TTL', 900),   // 15 minutes
            'preferences' => env('FEED_PREFERENCES_CACHE_TTL', 3600), // 1 hour
            'trending' => env('FEED_TRENDING_CACHE_TTL', 1800),      // 30 minutes
        ],
        'tags' => [
            'feed' => 'feed',
            'user' => 'feed_user',
            'activity' => 'feed_activity',
        ],
    ],

    // Pagination
    'pagination' => [
        'per_page' => env('FEED_PER_PAGE', 20),
        'max_per_page' => env('FEED_MAX_PER_PAGE', 50),
    ],

    // Ranking Algorithm Weights
    'ranking' => [
        'weights' => [
            'recency' => 0.35,        // Newer content prioritized
            'relevance' => 0.30,      // Followed artists/users
            'engagement' => 0.20,     // Popular content
            'diversity' => 0.10,      // Content type variety
            'personalization' => 0.05, // User preferences
        ],
        
        // Recency decay (exponential)
        'recency' => [
            'half_life_hours' => 24,  // Score halves every 24 hours
            'max_days' => 7,          // Don't show content older than 7 days
        ],
        
        // Relevance factors
        'relevance' => [
            'followed_artist' => 10.0,
            'followed_user' => 8.0,
            'friend_of_friend' => 5.0,
            'suggested' => 2.0,
        ],
        
        // Engagement thresholds
        'engagement' => [
            'viral_threshold' => 1000,  // Plays/likes for viral boost
            'popular_threshold' => 100,
            'trending_threshold' => 50,
        ],
        
        // Diversity (content type mix)
        'diversity' => [
            'max_consecutive_same_type' => 3,
            'preferred_mix' => [
                'music' => 0.40,      // 40% music activities
                'social' => 0.20,     // 20% social activities
                'events' => 0.15,     // 15% events
                'sacco' => 0.10,      // 10% SACCO updates
                'platform' => 0.10,   // 10% platform announcements
                'store' => 0.05,      // 5% store promotions
            ],
        ],
        
        // Penalties
        'penalties' => [
            'not_interested' => -50.0,   // Heavy penalty for hidden content
            'seen_recently' => -5.0,     // Light penalty for recently seen
            'spam_detected' => -100.0,   // Remove spam content
        ],
    ],

    // Activity Types Configuration
    'activity_types' => [
        'music' => [
            'song_upload' => ['priority' => 'high', 'ttl' => 7],
            'album_release' => ['priority' => 'high', 'ttl' => 14],
            'playlist_created' => ['priority' => 'medium', 'ttl' => 7],
            'song_liked' => ['priority' => 'low', 'ttl' => 3],
        ],
        'social' => [
            'user_followed' => ['priority' => 'medium', 'ttl' => 7],
            'friend_joined' => ['priority' => 'medium', 'ttl' => 7],
            'comment_posted' => ['priority' => 'low', 'ttl' => 3],
        ],
        'events' => [
            'event_created' => ['priority' => 'high', 'ttl' => 30],
            'event_starting' => ['priority' => 'urgent', 'ttl' => 1],
            'event_photos' => ['priority' => 'medium', 'ttl' => 7],
        ],
        'awards' => [
            'voting_opened' => ['priority' => 'high', 'ttl' => 30],
            'nomination_announced' => ['priority' => 'high', 'ttl' => 30],
            'winner_revealed' => ['priority' => 'high', 'ttl' => 14],
        ],
        'sacco' => [
            'dividend_distributed' => ['priority' => 'urgent', 'ttl' => 7],
            'loan_approved' => ['priority' => 'high', 'ttl' => 7],
            'milestone_reached' => ['priority' => 'medium', 'ttl' => 7],
        ],
        'store' => [
            'product_launched' => ['priority' => 'medium', 'ttl' => 14],
            'sale_announced' => ['priority' => 'high', 'ttl' => 7],
        ],
        'platform' => [
            'system_announcement' => ['priority' => 'high', 'ttl' => 14],
            'feature_launched' => ['priority' => 'medium', 'ttl' => 7],
        ],
    ],

    // Pre-generation Configuration
    'pregenerate' => [
        'enabled' => env('FEED_PREGENERATE_ENABLED', true),
        'active_users_limit' => env('FEED_PREGENERATE_USERS', 1000),
        'schedule' => 'hourly', // How often to regenerate
        'min_activity_threshold' => 10, // Min activities in last 7 days
    ],

    // A/B Testing Configuration
    'ab_testing' => [
        'enabled' => env('FEED_AB_TESTING_ENABLED', false),
        'variants' => [
            'control' => [
                'name' => 'Original Algorithm',
                'percentage' => 50,
                'weights' => [
                    'recency' => 0.35,
                    'relevance' => 0.30,
                    'engagement' => 0.20,
                    'diversity' => 0.10,
                    'personalization' => 0.05,
                ],
            ],
            'variant_a' => [
                'name' => 'Engagement Focused',
                'percentage' => 25,
                'weights' => [
                    'recency' => 0.25,
                    'relevance' => 0.25,
                    'engagement' => 0.35, // Increased engagement weight
                    'diversity' => 0.10,
                    'personalization' => 0.05,
                ],
            ],
            'variant_b' => [
                'name' => 'Personalization Focused',
                'percentage' => 25,
                'weights' => [
                    'recency' => 0.30,
                    'relevance' => 0.25,
                    'engagement' => 0.15,
                    'diversity' => 0.10,
                    'personalization' => 0.20, // Increased personalization
                ],
            ],
        ],
    ],

    // User Preferences
    'preferences' => [
        'default' => [
            'show_social_activities' => true,
            'show_event_updates' => true,
            'show_sacco_updates' => true,
            'show_store_promotions' => true,
            'show_platform_announcements' => true,
        ],
        'content_filters' => [
            'explicit_content' => 'show', // show, hide, blur
            'language_preference' => null, // null = all languages
            'genre_filters' => [], // Empty = all genres
        ],
    ],

    // Rate Limiting
    'rate_limiting' => [
        'enabled' => true,
        'requests_per_minute' => env('FEED_RATE_LIMIT', 60),
        'burst_limit' => env('FEED_BURST_LIMIT', 10),
    ],

    // Analytics
    'analytics' => [
        'enabled' => env('FEED_ANALYTICS_ENABLED', true),
        'track_events' => [
            'feed_viewed',
            'item_clicked',
            'item_liked',
            'item_shared',
            'item_hidden',
            'refresh_requested',
        ],
        'log_channel' => env('FEED_LOG_CHANNEL', 'daily'),
    ],

    // Performance Monitoring
    'monitoring' => [
        'enabled' => env('FEED_MONITORING_ENABLED', true),
        'slow_query_threshold' => 500, // milliseconds
        'alert_threshold' => [
            'cache_hit_rate' => 0.8, // Alert if below 80%
            'generation_time' => 2000, // Alert if > 2 seconds
        ],
    ],

];
