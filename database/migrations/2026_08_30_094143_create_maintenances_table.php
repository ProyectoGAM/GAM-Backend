<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poultry_house_id')->constrained()->restrictOnDelete();
            $table->date('maintenance_date');
            $table->text('description');
            $table->decimal('cost_amount', 19, 4);
            $table->char('cost_currency', 3);
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->string('responsible_name');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('completed');
            $table->unsignedInteger('version')->default(1);
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['poultry_house_id', 'maintenance_date', 'id']);
            $table->index(['poultry_house_id', 'status', 'maintenance_date', 'id'], 'maintenances_latest_index');
            $table->index('responsible_user_id');
        });

        DB::statement('ALTER TABLE maintenances ADD CONSTRAINT maintenances_cost_check CHECK (cost_amount >= 0)');
        DB::statement("ALTER TABLE maintenances ADD CONSTRAINT maintenances_currency_check CHECK (cost_currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE maintenances ADD CONSTRAINT maintenances_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE maintenances ADD CONSTRAINT maintenances_description_check CHECK (length(trim(description)) BETWEEN 1 AND 5000)');
        DB::statement("ALTER TABLE maintenances ADD CONSTRAINT maintenances_status_check CHECK (
            (status = 'completed' AND cancelled_at IS NULL AND cancellation_reason IS NULL)
            OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL
                AND length(trim(cancellation_reason)) BETWEEN 1 AND 1000)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenances');
    }
};
