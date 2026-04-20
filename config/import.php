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
    | Submit Storage (Worker-Shared Source)
    |--------------------------------------------------------------------------
    |
    | Files are promoted to this disk on submit so async workers in other
    | containers can always access a shared source (typically S3).
    |  s3_happytime defined in filesystems.php
    |
    */
    'submit' => [
        'disk' => env('IMPORT_SUBMIT_DISK', 'local'),
        'directory' => env('IMPORT_SUBMIT_DIRECTORY', 'import-kit/submitted'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Local Temp
    |--------------------------------------------------------------------------
    |
    | Worker copies source file from submit storage to this local directory
    | before parsing. Temporary files are cleaned up after processing.
    |
    */
    'worker' => [
        'local_temp_dir' => env('IMPORT_WORKER_LOCAL_TEMP_DIR', sys_get_temp_dir()),
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
    | Commit Dispatch Strategy
    |--------------------------------------------------------------------------
    |
    | Choose how async commit jobs are dispatched:
    | - single: one RunImportJob handles the whole import (legacy behavior)
    | - bus_batch: split import into chunked RunImportJob(s) via Laravel Bus batch
    |
    */
    'commit' => [
        'dispatch_mode' => env('IMPORT_COMMIT_DISPATCH_MODE', 'single'),
        'batch' => [
            'chunk_size' => (int) env('IMPORT_COMMIT_BATCH_CHUNK_SIZE', 500),
            'allow_failures' => (bool) env('IMPORT_COMMIT_BATCH_ALLOW_FAILURES', false),
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Header Policy Defaults
    |--------------------------------------------------------------------------
    |
    | Default spreadsheet header behavior used as fallback.
    | Recommended: implement HeaderPolicyAwareImportModuleInterface in each module
    | to provide per-kind policy from code (or DB-driven rules) instead of config.
    |
    | - row: header row index (1-based)
    | - strict_order: enforce expected labels by fixed column index
    | - strict_core_columns: [excel_column_index => expected_label]
    | - required_headers: normalized keys expected in parsed header_map
    | - custom_field_start_column: first custom field column (1-based)
    | - custom_field_pattern: regex to extract customFieldId (named group "id")
    | - normalize_mode: "snake" (default), "raw", or "snake_unaccent" (ASCII-ish keys, no diacritics)
    |
    */
    'header' => [
        'default' => [
            'row' => 1,
            'strict_order' => false,
            'strict_core_columns' => [],
            'required_headers' => [],
            'optional_headers' => [],
            'custom_field_start_column' => null,
            'custom_field_pattern' => '/\|\s*(?<id>[A-Za-z0-9_-]+)\s*$/',
            'normalize_mode' => 'snake',
        ],
        // Backward-compatible fallback per kind if module does not provide policy.
        'kinds' => [],
    ],
];
