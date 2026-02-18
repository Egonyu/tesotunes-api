<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automated database and file backups.
    | Backups are stored to DigitalOcean Spaces (or local disk).
    |
    */

    // Enable automatic scheduled backups
    'auto_enabled' => env('BACKUP_ENABLED', false),

    // Schedule: hourly, twicedaily, daily, weekly, monthly
    'schedule' => env('BACKUP_SCHEDULE', 'daily'),

    // Number of days to retain backups
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    // Storage disk for backups (should match a disk in filesystems.php)
    'disk' => env('BACKUP_DISK', 'local'),

    // Path within the disk to store backups
    'path' => env('BACKUP_PATH', 'backups/tesotunes'),

    // Database connection to backup (null = default)
    'database_connection' => env('BACKUP_DB_CONNECTION', null),

    // Directories to include in full backups (relative to base_path)
    'include_dirs' => [
        'storage/app/public',
    ],

    // Directories to exclude from full backups
    'exclude_dirs' => [
        'vendor',
        'node_modules',
        'storage/logs',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
    ],

    // Maximum backup file size in MB (0 = unlimited)
    'max_file_size' => (int) env('BACKUP_MAX_FILE_SIZE', 500),

    // Notification channels for backup events
    'notifications' => [
        'on_success' => env('BACKUP_NOTIFY_SUCCESS', false),
        'on_failure' => env('BACKUP_NOTIFY_FAILURE', true),
        'channels' => ['log'], // log, mail, slack
    ],
];
