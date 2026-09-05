<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 160);
            $table->text('description');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
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

        DB::statement('ALTER TABLE medicines ADD CONSTRAINT medicines_name_check CHECK (length(trim(name)) BETWEEN 1 AND 160)');
        DB::statement('ALTER TABLE medicines ADD CONSTRAINT medicines_description_check CHECK (length(trim(description)) BETWEEN 1 AND 5000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
