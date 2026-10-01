<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_loads', function (Blueprint $table): void {
            $table->json('items')->nullable();
            $table->string('request_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_loads', function (Blueprint $table): void {
            $table->dropColumn(['items', 'request_hash']);
        });
    }
};
