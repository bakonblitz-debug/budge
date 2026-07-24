<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the user owes outside of statement-tracked credit accounts: mortgage,
 * loans, student loans, etc. Balances are entered manually and subtract from
 * net worth. (Credit-card/LOC balances come from bank_accounts instead.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['mortgage', 'loan', 'student_loan', 'credit_card', 'other'])->default('loan');
            $table->string('institution')->nullable();
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liabilities');
    }
};
