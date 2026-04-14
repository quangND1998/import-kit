<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Models;

use Illuminate\Database\Eloquent\Model;

final class ImportJob extends Model
{
    protected $table = 'import_jobs';

    protected $guarded = [];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public $incrementing = false;

    protected $keyType = 'string';
}
