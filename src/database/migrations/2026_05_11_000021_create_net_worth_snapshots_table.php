<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time net-worth records (one per day max) so net worth can be charted
 * over time. Written by NetWorthService::snapshot().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('net_worth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('as_of');
            $table->decimal('total_cash', 14, 2)->default(0);
            $table->decimal('total_assets', 14, 2)->default(0);
            $table->decimal('total_liabilities', 14, 2)->default(0);
            $table->decimal('net_worth', 14, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('net_worth_snapshots');
    }
};
