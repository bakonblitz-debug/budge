<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Need-vs-want axis, ORTHOGONAL to the parent_id topic hierarchy
            // (Food contains Groceries=need AND Restaurants=want), so it lives on
            // the category itself. Nullable: null = unclassified (surfaced in the
            // UI to nudge the user). `excluded` covers Transfers/Income/Between
            // Accounts so the needs/wants meter can ignore them.
            $table->enum('kind', ['need', 'want', 'saving', 'investment', 'excluded'])
                ->nullable()
                ->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
