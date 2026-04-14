<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('import_preview_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind');
            $table->string('file_handle');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->json('context')->nullable();
            $table->string('status')->default('uploaded');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['kind', 'status']);
            $table->index(['tenant_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_preview_sessions');
    }
};
