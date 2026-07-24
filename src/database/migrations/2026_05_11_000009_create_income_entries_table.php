<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source')->comment('e.g. Employer - Salary, Freelance');
            $table->decimal('amount', 12, 2)->comment('Positive amount');
            $table->enum('frequency', ['bi_weekly', 'weekly', 'monthly', 'one_time'])->default('bi_weekly');
            $table->date('pay_date');
            $table->boolean('is_net')->default(true)->comment('true = after-tax');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'pay_date']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_entries');
    }
};
