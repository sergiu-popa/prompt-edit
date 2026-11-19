<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->bigInteger('user_estimate_min_nanodollars');
            $table->bigInteger('user_estimate_typical_nanodollars');
            $table->bigInteger('user_estimate_max_nanodollars');

            $table->string('provider_id')->nullable()->unique();

            $table->bigInteger('input_text_tokens')->nullable();
            $table->bigInteger('input_image_tokens')->nullable();
            $table->bigInteger('output_tokens')->nullable();
            $table->bigInteger('reasoning_tokens')->nullable();
            $table->json('rates')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_param')->nullable();
            $table->string('local_failure_reason')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();

            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
