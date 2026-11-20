<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the reconciler next checks a row (D-50); null means it never has. The
     * partial index holds only the rows the poll step can select, confirmed and
     * non-terminal, in the order it reads them, so it stays small however many
     * terminal rows the table holds. Blueprint has no partial index, hence the
     * raw statement.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestampTz('reconcile_recheck_at')->nullable();
        });

        DB::statement(<<<'SQL'
            create index generations_reconcile_poll_index on generations (last_provider_update_at, id)
            where provider_id is not null and status in ('queued', 'processing')
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index generations_reconcile_poll_index');

        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn('reconcile_recheck_at');
        });
    }
};
