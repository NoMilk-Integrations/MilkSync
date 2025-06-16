<?php

return [
    'connections' => [
        'production' => [
            'host' => env('SYNC_PROD_DB_HOST'),
            'port' => env('SYNC_PROD_DB_PORT', 3306),
            'database' => env('SYNC_PROD_DB_DATABASE'),
            'username' => env('SYNC_PROD_DB_USERNAME'),
            'password' => env('SYNC_PROD_DB_PASSWORD'),
        ],
    ],
    'default_connection' => 'production',
    'default_excludes' => [
        'activity_log',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'telescope_watchers',
        'jobs',
        'failed_jobs',
        'password_resets',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'job_batches',
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
        'migrations',
    ],
    'backup' => [
        'path' => storage_path('app/milksync-backups'),
        'keep_backups' => 3,
    ],
    'mysql' => [
        'dump_options' => [
            '--routines',
            '--triggers',
            '--single-transaction',
            '--lock-tables=false',
            '--no-tablespaces',
            '--ssl-mode=DISABLED',
        ],
        'import_options' => [
            '--force',
        ],
    ],
];