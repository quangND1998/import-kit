<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('import_job_errors', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('job_id');
            $table->unsignedInteger('line')->nullable();
            $table->string('field')->nullable();
            $table->string('code');
            $table->string('message');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'line']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_job_errors');
    }
};
