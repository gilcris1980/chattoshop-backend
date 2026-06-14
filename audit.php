<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== MIGRATIONS ===\n";
$migrations = DB::table('migrations')->orderBy('id')->get();
foreach ($migrations as $m) {
    echo "{$m->migration} | batch {$m->batch}\n";
}

echo "\n=== USERS ===\n";
$users = DB::table('users')->select('id', 'email', 'role', 'seller_status', 'email_verified_at')->orderBy('id')->get();
foreach ($users as $u) {
    echo "ID:{$u->id} | email:{$u->email} | role:{$u->role} | seller_status:{$u->seller_status} | email_verified_at:{$u->email_verified_at}\n";
}

echo "\n=== PRODUCTS (product_status) ===\n";
$products = DB::table('products')->select('id', 'name', 'product_status')->orderBy('id')->get();
foreach ($products as $p) {
    echo "ID:{$p->id} | name:{$p->name} | product_status:{$p->product_status}\n";
}

echo "\n=== TOKENS ===\n";
$tokens = DB::table('personal_access_tokens')->select('id', 'tokenable_id', 'name', 'abilities')->get();
foreach ($tokens as $t) {
    echo "ID:{$t->id} | user_id:{$t->tokenable_id} | name:{$t->name} | abilities:" . json_encode($t->abilities) . "\n";
}

echo "\n=== DONE ===\n";
