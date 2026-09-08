<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccines', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->text('description');
            $table->text('details')->nullable();
            $table->string('supplier_name_snapshot', 160);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('created_by_name');
            $table->uuid('operation_id')->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->timestampsTz();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['created_at', 'id']);
            $table->index(['supplier_id', 'created_at', 'id']);
        });

        DB::statement('ALTER TABLE vaccines ADD CONSTRAINT vaccines_description_check CHECK (length(trim(description)) BETWEEN 1 AND 5000)');
        DB::statement('ALTER TABLE vaccines ADD CONSTRAINT vaccines_details_check CHECK (details IS NULL OR length(trim(details)) <= 5000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccines');
    }
};
