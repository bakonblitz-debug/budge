<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 10, 2)->comment('Amount per period (positive)');
            $table->enum('frequency', ['monthly', 'bi_weekly', 'weekly', 'quarterly', 'yearly'])->default('monthly');
            $table->unsignedTinyInteger('due_day')->nullable()->comment('Day of month 1-31');
            $table->date('start_date');
            $table->date('end_date')->nullable()->comment('NULL = ongoing');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_expenses');
    }
};
