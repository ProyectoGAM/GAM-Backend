<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customer_account_movements
            DROP CONSTRAINT customer_movement_method_check,
            ADD CONSTRAINT customer_movement_method_check
                CHECK (payment_method IS NULL OR payment_method IN ('cash', 'transfer', 'check', 'other'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE customer_account_movements
            DROP CONSTRAINT customer_movement_method_check,
            ADD CONSTRAINT customer_movement_method_check
                CHECK (payment_method IS NULL OR payment_method IN ('cash', 'transfer', 'other'))");
    }
};
