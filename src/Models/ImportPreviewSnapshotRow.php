<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Models;

use Illuminate\Database\Eloquent\Model;

final class ImportPreviewSnapshotRow extends Model
{
    protected $table = 'import_preview_snapshot_rows';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];
}
