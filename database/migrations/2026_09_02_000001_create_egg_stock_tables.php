<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egg_stock_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_unit_id')->unique()->constrained('production_units')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_location_id')->unique()->constrained('stock_locations')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['production_unit_id', 'product_id']);
        });

        Schema::create('egg_stock_transactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('production_unit_id')->constrained('production_units')->restrictOnDelete();
            $table->foreignId('egg_stock_account_id')->constrained('egg_stock_accounts')->restrictOnDelete();
            $table->string('type', 40);
            $table->unsignedInteger('quantity');
            $table->timestampTz('occurred_at');
            $table->string('reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->unsignedInteger('version')->default(1);
            $table->string('reference_type', 120)->nullable();
            $table->string('reference_id', 80)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['production_unit_id', 'occurred_at', 'id']);
            $table->index(['egg_stock_account_id', 'status', 'occurred_at', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });
        DB::statement("ALTER TABLE egg_stock_transactions ADD CONSTRAINT egg_stock_transactions_values_check CHECK (quantity > 0 AND version > 0 AND status IN ('recorded', 'cancelled') AND type IN ('collection_receipt', 'manual_receipt', 'distribution_preparation', 'loss'))");

        Schema::create('egg_stock_transaction_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('egg_stock_transaction_id')->constrained('egg_stock_transactions')->restrictOnDelete();
            $table->uuid('operation_id')->index();
            $table->string('action', 20);
            $table->jsonb('before');
            $table->jsonb('after');
            $table->string('correction_reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['egg_stock_transaction_id', 'created_at', 'id']);
        });
        DB::statement("ALTER TABLE egg_stock_transaction_revisions ADD CONSTRAINT egg_stock_transaction_revisions_action_check CHECK (action IN ('correct', 'cancel'))");

        Schema::create('egg_stock_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('command', 80);
            $table->char('request_hash', 64);
            $table->jsonb('result');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['created_by', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_stock_commands');
        Schema::dropIfExists('egg_stock_transaction_revisions');
        Schema::dropIfExists('egg_stock_transactions');
        Schema::dropIfExists('egg_stock_accounts');
    }
};
