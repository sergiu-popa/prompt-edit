<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the reconciler logged its one warning that the generation takes longer
     * than usual (D-56). History's notice itself needs no column.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestampTz('reconcile_stuck_warned_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn('reconcile_stuck_warned_at');
        });
    }
};
