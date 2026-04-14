<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('import_preview_snapshot_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('session_id');
            $table->unsignedInteger('line');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
            $table->index(['session_id', 'line']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_preview_snapshot_rows');
    }
};
