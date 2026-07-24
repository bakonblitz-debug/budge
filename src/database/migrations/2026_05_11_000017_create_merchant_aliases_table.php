<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-editable rules that map a raw bank description (e.g.
 * "METRO PLUS SOMEVILLE QC") to a clean display name ("Metro"). Mirrors the
 * shape of category_rules: a match_type + match_value, highest priority first.
 * The resolved name is cached on transactions.merchant_name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('match_type', ['contains', 'starts_with', 'ends_with', 'exact', 'regex']);
            $table->string('match_value');
            $table->string('display_name');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'priority']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('merchant_name')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('merchant_name');
        });

        Schema::dropIfExists('merchant_aliases');
    }
};
