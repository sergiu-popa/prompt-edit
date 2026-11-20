<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The reconciler's stuck step reads non-terminal rows not yet warned about, oldest
     * created first (D-56). Terminal rows are never marked as warned, so the status
     * list is part of the predicate: the index holds only the rows the step can
     * select and stays small however many terminal rows the table holds. Blueprint
     * has no partial index, hence the raw statement.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create index generations_stuck_unwarned_index on generations (created_at, id)
            where status in ('pending', 'queued', 'processing') and reconcile_stuck_warned_at is null
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index generations_stuck_unwarned_index');
    }
};
