<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Generate form's submission, so a double click or a resent request creates one
     * generation (D-38). Null for rows not created from the form; Postgres keeps NULLs
     * distinct, so those never collide.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->uuid('submission_token')->nullable();
            $table->unique(['user_id', 'submission_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'submission_token']);
            $table->dropColumn('submission_token');
        });
    }
};
