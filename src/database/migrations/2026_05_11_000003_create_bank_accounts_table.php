<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['chequing', 'savings', 'credit_card', 'line_of_credit', 'other'])->default('chequing');
            $table->decimal('credit_limit', 12, 2)->nullable()->comment('For credit cards and LOC');
            $table->decimal('current_balance', 12, 2)->default(0)->comment('Last known balance');
            $table->char('currency', 3)->default('CAD');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('user_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
