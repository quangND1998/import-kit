<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Models;

use Illuminate\Database\Eloquent\Model;

final class ImportJobError extends Model
{
    protected $table = 'import_job_errors';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];
}
