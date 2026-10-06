<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_units', function (Blueprint $table) {
            $table->string('address', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_units', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
