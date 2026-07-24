<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A detected recurring commitment (subscription, membership, regular bill).
 * Built deterministically from transaction history: same merchant, regular
 * interval, stable amount. Refreshed by RecurringDetector — not user-authored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('merchant_key'); // normalized merchant the series groups on
            $table->enum('cadence', ['weekly', 'bi_weekly', 'monthly', 'quarterly', 'yearly']);
            $table->decimal('expected_amount', 12, 2); // typical spend magnitude (positive)
            $table->decimal('last_amount', 12, 2);
            $table->boolean('amount_increased')->default(false);
            $table->unsignedInteger('occurrences')->default(0);
            $table->date('last_seen_at');
            $table->date('next_expected_at')->nullable();
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'merchant_key']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_series');
    }
};
