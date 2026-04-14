<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Models;

use Illuminate\Database\Eloquent\Model;

final class ImportPreviewSession extends Model
{
    protected $table = 'import_preview_sessions';

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'expires_at' => 'datetime',
    ];

    public $incrementing = false;

    protected $keyType = 'string';
}
