<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('import_job_result_rows', function (Blueprint $table): void {
            $table->unique(['job_id', 'line'], 'import_job_result_rows_job_line_unique');
        });

        Schema::table('import_job_errors', function (Blueprint $table): void {
            $table->unique(['job_id', 'line', 'field', 'code'], 'import_job_errors_job_line_field_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('import_job_result_rows', function (Blueprint $table): void {
            $table->dropUnique('import_job_result_rows_job_line_unique');
        });

        Schema::table('import_job_errors', function (Blueprint $table): void {
            $table->dropUnique('import_job_errors_job_line_field_code_unique');
        });
    }
};
