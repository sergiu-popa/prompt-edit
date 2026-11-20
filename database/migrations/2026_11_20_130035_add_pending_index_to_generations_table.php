<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The reconciler's unsubmitted step reads `pending` rows oldest first (D-52). Only
     * those rows are indexed, so the index stays small however many rows have moved
     * on. Blueprint has no partial index, hence the raw statement.
     */
    public function up(): void
    {
        DB::statement("create index generations_pending_index on generations (created_at, id) where status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index generations_pending_index');
    }
};
