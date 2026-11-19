<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null until the generation is terminal; 0 and 0 once it failed.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->bigInteger('provider_cost_nanodollars')->nullable();
            $table->bigInteger('user_charge_nanodollars')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn(['provider_cost_nanodollars', 'user_charge_nanodollars']);
        });
    }
};
