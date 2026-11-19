<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ledger entries are append-only (D-08). The model refuses an update or a
     * delete, and this trigger refuses the raw query-builder writes that
     * bypass the model, so no path in the app can change an entry. TRUNCATE
     * fires no row trigger, so test truncation still empties the table.
     * `migrate:fresh` drops the tables but keeps functions, hence OR REPLACE.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_credit_ledger_entry_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Credit ledger entry % is append-only: % refused.', OLD.id, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER credit_ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON credit_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION refuse_credit_ledger_entry_change();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS credit_ledger_entries_append_only ON credit_ledger_entries;
            DROP FUNCTION IF EXISTS refuse_credit_ledger_entry_change();
            SQL);
    }
};
