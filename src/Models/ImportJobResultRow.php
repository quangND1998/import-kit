<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Models;

use Illuminate\Database\Eloquent\Model;

final class ImportJobResultRow extends Model
{
    protected $table = 'import_job_result_rows';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];
}
