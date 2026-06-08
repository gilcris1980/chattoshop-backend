<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users))");
    }

    public function down(): void
    {
        DB::statement("SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users))");
    }
};
