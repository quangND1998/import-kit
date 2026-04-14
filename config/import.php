<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Persistence Driver
    |--------------------------------------------------------------------------
    |
    | Determines where preview sessions / import jobs are persisted.
    | Supported values: "mysql", "mongo".
    |
    */
    'storage_driver' => env('IMPORT_STORAGE_DRIVER', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | History Tracking
    |--------------------------------------------------------------------------
    |
    | Toggle storing import history records (jobs, errors, result rows).
    |
    */
    'history' => [
        'enabled' => env('IMPORT_HISTORY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace Scope
    |--------------------------------------------------------------------------
    |
    | Keep this true if your host app allows imports without workspace context.
    |
    */
    'workspace_id_nullable' => true,

    /*
    |--------------------------------------------------------------------------
    | Database / Collection Names
    |--------------------------------------------------------------------------
    |
    | Customize table/collection names if they must align with host app naming.
    |
    */
    'database' => [
        'mysql' => [
            'preview_sessions_table' => 'import_preview_sessions',
            'preview_snapshot_rows_table' => 'import_preview_snapshot_rows',
            'jobs_table' => 'import_jobs',
            'job_errors_table' => 'import_job_errors',
            'job_result_rows_table' => 'import_job_result_rows',
        ],
        'mongo' => [
            'connection' => env('IMPORT_MONGO_CONNECTION', 'mongodb'),
            'preview_sessions_collection' => 'import_preview_sessions',
            'preview_snapshot_rows_collection' => 'import_preview_snapshot_rows',
            'jobs_collection' => 'import_jobs',
            'job_errors_collection' => 'import_job_errors',
            'job_result_rows_collection' => 'import_job_result_rows',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Storage
    |--------------------------------------------------------------------------
    |
    | Disk and base directory used by FileStore.
    | Typical values:
    | - local: fast preview / temporary files
    | - s3: durable storage for async workers
    |
    */
    'files' => [
        // Any Laravel filesystem disk defined in filesystems.php: local, s3, ...
        'disk' => env('IMPORT_FILES_DISK', 'local'),
        'directory' => env('IMPORT_FILES_DIRECTORY', 'import-kit'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Preview Behavior
    |--------------------------------------------------------------------------
    |
    | expires_minutes: TTL for preview sessions before they are considered stale.
    | default_per_page: fallback pagination size for preview APIs.
    |
    */
    'preview' => [
        'expires_minutes' => (int) env('IMPORT_PREVIEW_EXPIRES_MINUTES', 120),
        'default_per_page' => (int) env('IMPORT_PREVIEW_PER_PAGE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Column Labels
    |--------------------------------------------------------------------------
    |
    | Optional UI-friendly labels returned in preview payloads.
    | You may provide per-kind mapping:
    |
    | 'column_labels' => [
    |     'default' => ['employee_code' => 'Employee code'],
    |     'employee' => ['full_name' => 'Full name'],
    | ],
    |
    */
    'column_labels' => [
        // kind => [column_key => label]
        'default' => [],
    ],
];
