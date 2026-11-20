<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Our clock when the transition action last applied a Pixelforge snapshot (D-50),
     * which the reconciler judges staleness by. A row Pixelforge already confirmed
     * gets its `updated_at` once: that was its last transition, because submit
     * bookkeeping never touches `updated_at` (D-45).
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestampTz('last_provider_update_at')->nullable();
        });

        DB::table('generations')
            ->whereNotNull('provider_id')
            ->update(['last_provider_update_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn('last_provider_update_at');
        });
    }
};
