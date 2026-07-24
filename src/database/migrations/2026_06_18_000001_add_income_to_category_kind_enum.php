<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give income categories their own `kind` ('income') so they can be resolved by
 * kind rather than by (duplicable, renameable) name. Historically income was
 * bucketed under 'excluded' alongside transfers, which made the two
 * indistinguishable and forced fragile name-based `->first()` resolution across
 * three surfaces (Dashboard, BudgetRuleAnalyzer, Income).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Portable enum widening: MODIFYs the column on MySQL and rebuilds the
        // CHECK constraint on SQLite (which is how Laravel emulates enums there).
        Schema::table('categories', function (Blueprint $table) {
            $table->enum('kind', ['need', 'want', 'saving', 'investment', 'excluded', 'income'])
                ->nullable()
                ->change();
        });

        // Backfill existing data across all users (raw query bypasses the
        // BelongsToUser global scope): every top-level "Income" tree and its
        // children move from their prior bucket to kind='income'.
        $incomeParentIds = DB::table('categories')
            ->whereNull('parent_id')
            ->whereRaw('LOWER(name) = ?', ['income'])
            ->pluck('id');

        if ($incomeParentIds->isNotEmpty()) {
            DB::table('categories')
                ->where(function ($q) use ($incomeParentIds) {
                    $q->whereIn('id', $incomeParentIds)
                        ->orWhereIn('parent_id', $incomeParentIds);
                })
                ->update(['kind' => 'income']);
        }
    }

    public function down(): void
    {
        // Return income categories to their prior 'excluded' bucket before
        // shrinking the enum, so no row holds a now-invalid value.
        DB::table('categories')->where('kind', 'income')->update(['kind' => 'excluded']);

        Schema::table('categories', function (Blueprint $table) {
            $table->enum('kind', ['need', 'want', 'saving', 'investment', 'excluded'])
                ->nullable()
                ->change();
        });
    }
};
