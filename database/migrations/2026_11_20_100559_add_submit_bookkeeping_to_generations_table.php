<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The submit job's record of its attempts (D-45), which PE-014's reconciler reads.
     * Every column is nullable or defaulted, so existing rows read as never attempted.
     * Only the submit path writes them, never the transition action.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestampTz('submit_first_attempted_at')->nullable();
            $table->timestampTz('submit_last_attempted_at')->nullable();
            $table->string('submit_last_outcome')->nullable();
            $table->timestampTz('submit_ambiguous_at')->nullable();
            $table->timestampTz('submit_handed_over_at')->nullable();
            $table->timestampTz('submit_first_overloaded_at')->nullable();
            $table->timestampTz('submit_first_concurrency_limited_at')->nullable();
            $table->smallInteger('submit_invalid_api_key_count')->default(0);
            $table->string('submit_retry_note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn([
                'submit_first_attempted_at',
                'submit_last_attempted_at',
                'submit_last_outcome',
                'submit_ambiguous_at',
                'submit_handed_over_at',
                'submit_first_overloaded_at',
                'submit_first_concurrency_limited_at',
                'submit_invalid_api_key_count',
                'submit_retry_note',
            ]);
        });
    }
};
