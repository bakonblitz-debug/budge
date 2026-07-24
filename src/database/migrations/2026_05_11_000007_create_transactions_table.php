<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('transaction_date');
            $table->string('description', 500);
            $table->decimal('amount', 12, 2)->comment('Signed: negative = expense, positive = income');
            $table->decimal('balance_after', 12, 2)->nullable()->comment('Running balance from bank');
            $table->text('notes')->nullable();
            $table->boolean('is_excluded')->default(false)->comment('Exclude from budget calcs (transfers, etc.)');
            $table->string('hash', 64)->unique()->comment('SHA-256 import match key (recipe/index changed by later migration)');
            $table->timestamps();

            $table->index('user_id');
            $table->index('bank_account_id');
            $table->index('category_id');
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
