<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fake Pixelforge's own state (PE-010), shared by the web and worker
 * processes. It also exists in production, unused, because the fake can
 * never be bound there.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fake_pixelforge_generations', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('idempotency_key')->unique();
            $table->char('request_hash', 64);
            // `json`, not `jsonb`, keeps the docs' key order in webhook bodies.
            $table->json('snapshot');
            $table->json('usage');
            $table->string('outcome');
            $table->unsignedInteger('processing_seconds');
            $table->unsignedInteger('image_ttl_seconds');
            $table->boolean('duplicates_webhooks')->default(false);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fake_pixelforge_generations');
    }
};
