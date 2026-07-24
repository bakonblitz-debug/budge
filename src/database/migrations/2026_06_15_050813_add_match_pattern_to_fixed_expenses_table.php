<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fixed_expenses', function (Blueprint $table) {
            // Optional merchant substring used to match this expense to its
            // transactions for accrual "spent". Null → fall back to the expense
            // name (preserves existing behaviour). Lets a user-typed name
            // ("Gym Membership") match a different bank merchant ("GOODLIFE
            // FITNESS #221") without renaming the expense.
            $table->string('match_pattern', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_expenses', function (Blueprint $table) {
            $table->dropColumn('match_pattern');
        });
    }
};
