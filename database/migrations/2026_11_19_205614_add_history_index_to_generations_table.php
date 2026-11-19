<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * History lists a user's generations newest first. The index also covers the user
     * foreign key, which Postgres does not index on its own, and `id` breaks a tie
     * between two rows created in the same second.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at', 'id']);
        });
    }
};
