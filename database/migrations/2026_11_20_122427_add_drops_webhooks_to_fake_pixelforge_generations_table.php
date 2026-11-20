<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the fake sends this generation's webhooks at all (D-55), decided at
     * create. Existing rows keep theirs.
     */
    public function up(): void
    {
        Schema::table('fake_pixelforge_generations', function (Blueprint $table): void {
            $table->boolean('drops_webhooks')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fake_pixelforge_generations', function (Blueprint $table): void {
            $table->dropColumn('drops_webhooks');
        });
    }
};
