<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yearly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('total_income', 12, 2)->default(0);
            $table->decimal('total_expenses', 12, 2)->default(0);
            $table->decimal('total_fixed', 12, 2)->default(0);
            $table->decimal('total_variable', 12, 2)->default(0);
            $table->decimal('net_savings', 12, 2)->default(0);
            $table->decimal('savings_rate', 5, 2)->default(0);
            $table->decimal('expense_ratio', 5, 2)->default(0);
            $table->enum('life_direction', ['improving', 'stable', 'declining'])->default('stable');
            $table->json('metadata')->nullable()->comment('Category breakdown, monthly detail');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yearly_snapshots');
    }
};
