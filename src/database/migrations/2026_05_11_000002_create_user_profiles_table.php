<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('gross_salary', 12, 2)->nullable()->comment('Annual gross salary');
            $table->decimal('net_pay_per_period', 12, 2)->nullable()->comment('Net take-home per pay period');
            $table->enum('pay_frequency', ['bi_weekly', 'weekly', 'monthly', 'semi_monthly'])->default('bi_weekly');
            $table->date('budget_start_date')->nullable()->comment('Move-in date or budget tracking start');
            $table->char('currency', 3)->default('CAD');
            $table->string('province', 50)->default('QC')->comment('Province for tax context');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
