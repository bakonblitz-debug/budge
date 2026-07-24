<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `dismissed` state (user hid a false positive) to recurring_series and
 * an `is_manual` flag for series the user added by hand. Both are honored by
 * RecurringDetector so a rescan never resurrects a dismissal nor ends a manual
 * series. The `status`/`cadence` enums are widened to plain strings so new
 * states (`dismissed`) and cadences (`bi_monthly`, `semi_annual`) don't require
 * fragile per-driver enum surgery, and so SQLite (tests) accepts them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Widen the enum columns to varchars in place (drops the CHECK).
            DB::statement("ALTER TABLE recurring_series MODIFY status VARCHAR(255) NOT NULL DEFAULT 'active'");
            DB::statement('ALTER TABLE recurring_series MODIFY cadence VARCHAR(255) NOT NULL');
        } else {
            // SQLite stores enums as CHECK constraints; rebuild via the schema
            // builder, which recreates the table without them.
            Schema::table('recurring_series', function (Blueprint $table) {
                $table->string('status')->default('active')->change();
                $table->string('cadence')->change();
            });
        }

        Schema::table('recurring_series', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_series', function (Blueprint $table) {
            $table->dropColumn('is_manual');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE recurring_series SET status = 'ended' WHERE status = 'dismissed'");
            DB::statement("ALTER TABLE recurring_series MODIFY status ENUM('active', 'ended') NOT NULL DEFAULT 'active'");
            DB::statement("ALTER TABLE recurring_series MODIFY cadence ENUM('weekly', 'bi_weekly', 'monthly', 'quarterly', 'yearly') NOT NULL");
        }
    }
};
