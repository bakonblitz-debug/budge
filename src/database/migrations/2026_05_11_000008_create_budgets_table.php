<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->comment('Target amount (positive)');
            $table->enum('period', ['monthly', 'weekly', 'yearly'])->default('monthly');
            $table->date('start_date');
            $table->date('end_date')->nullable()->comment('NULL = ongoing');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'start_date']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
