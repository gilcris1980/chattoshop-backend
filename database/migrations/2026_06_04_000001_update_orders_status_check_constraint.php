<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE orders SET status = 'processing' WHERE status = 'approved'");

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE orders SET status = 'approved' WHERE status = 'processing'");

        DB::statement("UPDATE orders SET status = 'shipped' WHERE status = 'delivered'");

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'approved', 'shipped', 'completed', 'cancelled'))");
    }
};
