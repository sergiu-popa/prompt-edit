<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grants and purchases top a balance up (D-67). Neither belongs to a generation,
     * so the CHECKs bind holds, releases and charges to one and keep top-ups free of
     * one, which also closes PE-007's open guard. A purchase carries the Buy credits
     * page's token, unique per user, so a double click records one purchase (D-71);
     * NULLs stay distinct, so no other entry collides. Its leading `user_id` also
     * serves the balance aggregate. The partial index allows one grant per user,
     * starter or opening (D-70). Blueprint has neither CHECKs nor partial indexes,
     * hence the raw statements. Every existing row satisfies them.
     */
    public function up(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table): void {
            $table->uuid('purchase_token')->nullable();
            $table->unique(['user_id', 'purchase_token']);
        });

        DB::unprepared(<<<'SQL'
            alter table credit_ledger_entries
                add constraint credit_ledger_entries_type_check
                    check (type in ('hold', 'release', 'charge', 'grant', 'purchase')),
                add constraint credit_ledger_entries_generation_check
                    check ((generation_id is not null) = (type in ('hold', 'release', 'charge'))),
                add constraint credit_ledger_entries_purchase_token_check
                    check ((purchase_token is not null) = (type = 'purchase')),
                add constraint credit_ledger_entries_top_up_amount_check
                    check (type not in ('grant', 'purchase') or amount_nanodollars > 0);

            create unique index credit_ledger_entries_one_grant_per_user
                on credit_ledger_entries (user_id) where type = 'grant';
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop index credit_ledger_entries_one_grant_per_user;

            alter table credit_ledger_entries
                drop constraint credit_ledger_entries_type_check,
                drop constraint credit_ledger_entries_generation_check,
                drop constraint credit_ledger_entries_purchase_token_check,
                drop constraint credit_ledger_entries_top_up_amount_check;
            SQL);

        Schema::table('credit_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'purchase_token']);
            $table->dropColumn('purchase_token');
        });
    }
};
