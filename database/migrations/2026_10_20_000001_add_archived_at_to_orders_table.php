<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NOTE: The production database already has an `archived_at` column on
     * `orders` (added out-of-band). This migration is guarded so that running
     * it is a no-op where the column already exists — it never recreates or
     * alters the existing production column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'archived_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('notes');
        });
    }

    /**
     * Intentionally a no-op: the production column pre-exists and must never
     * be dropped by a rollback. Dropping it would destroy archive history.
     */
    public function down(): void
    {
        //
    }
};
