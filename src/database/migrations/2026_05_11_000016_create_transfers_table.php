<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links the two legs of a money transfer between the user's own accounts
 * (e.g. chequing → investments, card payments). Both legs also carry the
 * resulting transfer_id so spend/income aggregation can exclude them with a
 * single `whereNull('transfer_id')` — transfers are not real expense/income.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('to_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->enum('detected_via', ['auto', 'manual'])->default('manual');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('transfer_id')->nullable()->after('category_id')
                ->constrained('transfers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_id');
        });

        Schema::dropIfExists('transfers');
    }
};
