<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A confirmed generation Pixelforge keeps answering `not_found` for (D-49): the
     * first `not_found` of the current streak, and when the one operator log for it
     * was written. Any snapshot clears both.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestampTz('reconcile_not_found_since')->nullable();
            $table->timestampTz('reconcile_not_found_reported_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn(['reconcile_not_found_since', 'reconcile_not_found_reported_at']);
        });
    }
};
