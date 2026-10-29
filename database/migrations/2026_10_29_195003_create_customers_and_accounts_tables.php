<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 160)->nullable();
            $table->string('business_name', 160)->nullable();
            $table->string('document_type', 40)->nullable();
            $table->string('document_number', 80)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('email', 254)->nullable();
            $table->foreignId('locality_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('address', 240)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('visit_frequency', 240)->nullable();
            $table->text('commercial_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'id']);
            $table->index(['latitude', 'longitude']);
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_point_check CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_document_check CHECK ((document_type IS NULL) = (document_number IS NULL))');

        Schema::create('customer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            // Misma capacidad que los importes históricos de Repartos.
            $table->decimal('balance', 22, 3)->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_account_version_check CHECK (version >= 0)');

        Schema::table('delivery_stops', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('accounting_status', 24)->default('legacy_unreconciled');
            $table->unique(['delivery_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE delivery_stops ADD CONSTRAINT delivery_stop_accounting_check CHECK (accounting_status IN ('legacy_unreconciled', 'posted', 'not_applicable'))");

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->jsonb('financial_snapshot')->nullable();
        });

        Schema::create('customer_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('kind', 40);
            $table->string('request_hash', 64);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('result')->default('{}');
            $table->timestampTz('created_at');
            $table->unique(['created_by', 'idempotency_key']);
        });

        Schema::create('customer_account_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_operation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 24);
            $table->decimal('amount', 22, 3);
            $table->decimal('balance_before', 22, 3);
            $table->decimal('balance_after', 22, 3);
            $table->string('currency', 3)->default('UYU');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('actor_name', 160);
            $table->string('customer_name', 160);
            $table->text('reason')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 240)->nullable();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_stop_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->unique()->constrained('customer_account_movements')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->unique(['customer_account_id', 'sequence']);
            $table->index(['customer_account_id', 'created_at', 'id'], 'customer_movements_history_index');
            $table->index(['delivery_id', 'type']);
        });
        DB::statement("ALTER TABLE customer_account_movements ADD CONSTRAINT customer_movement_amount_check CHECK ((type IN ('sale_charge', 'adjustment_increase') AND amount > 0) OR (type IN ('payment', 'adjustment_decrease') AND amount < 0) OR (type = 'reversal' AND amount <> 0))");
        DB::statement("ALTER TABLE customer_account_movements ADD CONSTRAINT customer_movement_balance_check CHECK (currency = 'UYU' AND sequence > 0 AND balance_after = balance_before + amount)");
        DB::statement("ALTER TABLE customer_account_movements ADD CONSTRAINT customer_movement_reason_check CHECK (type NOT IN ('adjustment_increase', 'adjustment_decrease', 'reversal') OR (reason IS NOT NULL AND length(trim(reason)) BETWEEN 1 AND 2000))");
        DB::statement("ALTER TABLE customer_account_movements ADD CONSTRAINT customer_movement_reversal_check CHECK ((type = 'reversal') = (reverses_movement_id IS NOT NULL))");
        DB::statement("ALTER TABLE customer_account_movements ADD CONSTRAINT customer_movement_method_check CHECK (payment_method IS NULL OR payment_method IN ('cash', 'transfer', 'other'))");
        DB::statement("CREATE UNIQUE INDEX customer_movement_sale_origin_unique ON customer_account_movements (delivery_stop_id) WHERE type = 'sale_charge'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_movements');
        Schema::dropIfExists('customer_operations');
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropColumn('financial_snapshot'));
        DB::statement('ALTER TABLE delivery_stops DROP CONSTRAINT IF EXISTS delivery_stop_accounting_check');
        Schema::table('delivery_stops', function (Blueprint $table): void {
            $table->dropUnique(['delivery_id', 'customer_id']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('accounting_status');
        });
        Schema::dropIfExists('customer_accounts');
        Schema::dropIfExists('customers');
    }
};
