<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderArchiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->enum('role', ['system_admin', 'admin', 'seller', 'customer'])->default('customer');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('avatar')->nullable();
            $table->string('seller_status')->nullable();
        });

        Schema::create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function ($table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('category_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock');
            $table->string('image')->nullable();
            $table->boolean('status')->default(true);
            $table->string('product_status')->default('approved');
            $table->timestamps();
        });

        Schema::create('orders', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'])->default('pending');
            $table->text('shipping_address')->nullable();
            $table->string('payment_method')->default('cod');
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function ($table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        Schema::create('notifications', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('order');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    private function makeUser(string $email, string $role, ?string $sellerStatus = null): User
    {
        $user = User::create([
            'name' => $role . ' User',
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => $role,
            'seller_status' => $sellerStatus,
        ]);

        // Order routes sit behind the 'verified' middleware.
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeProduct(User $seller, string $name = 'Mouse', int $stock = 10): Product
    {
        return Product::create([
            'seller_id' => $seller->id,
            'name' => $name,
            'slug' => strtolower($name) . '-' . $seller->id . '-' . uniqid(),
            'price' => 199.99,
            'stock' => $stock,
            'status' => true,
            'product_status' => 'approved',
        ]);
    }

    private function makeOrder(User $customer, Product $product, string $status, int $quantity = 1): Order
    {
        $order = Order::create([
            'user_id' => $customer->id,
            'total_amount' => 199.99 * $quantity,
            'status' => $status,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => 199.99,
        ]);

        return $order;
    }

    public function test_system_admin_can_archive_cancelled_order_without_side_effects(): void
    {
        $admin = $this->makeUser('sysadmin-archive@example.com', 'system_admin');
        $seller = $this->makeUser('seller-archive@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-archive@example.com', 'customer');
        $product = $this->makeProduct($seller);
        $order = $this->makeOrder($customer, $product, 'cancelled');

        $stockBefore = $product->fresh()->stock;
        $notificationsBefore = \DB::table('notifications')->count();

        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/archive")
            ->assertOk()
            ->assertJsonStructure(['order', 'message']);

        $fresh = $order->fresh();

        // Stamped, not deleted: status untouched, items intact.
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());

        // No stock, payment or notification side effects.
        $this->assertSame($stockBefore, $product->fresh()->stock);
        $this->assertSame('199.99', (string) $fresh->total_amount);
        $this->assertSame($notificationsBefore, \DB::table('notifications')->count());
    }

    public function test_archive_endpoint_rejects_customers_sellers_and_regular_admins(): void
    {
        $seller = $this->makeUser('seller-auth@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-auth@example.com', 'customer');
        $admin = $this->makeUser('admin-auth@example.com', 'admin');
        $product = $this->makeProduct($seller);
        $order = $this->makeOrder($customer, $product, 'cancelled');

        Sanctum::actingAs($customer);
        $this->postJson("/api/orders/{$order->id}/archive")->assertStatus(403);

        Sanctum::actingAs($seller);
        $this->postJson("/api/orders/{$order->id}/archive")->assertStatus(403);

        // 'admin' role is intentionally not enough — system_admin only.
        Sanctum::actingAs($admin);
        $this->postJson("/api/orders/{$order->id}/archive")->assertStatus(403);

        $this->assertNull($order->fresh()->archived_at);
    }

    public function test_archive_rejects_every_non_cancelled_status(): void
    {
        $admin = $this->makeUser('sysadmin-status@example.com', 'system_admin');
        $seller = $this->makeUser('seller-status@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-status@example.com', 'customer');
        $product = $this->makeProduct($seller);

        Sanctum::actingAs($admin);

        foreach (['pending', 'processing', 'shipped', 'delivered', 'completed'] as $status) {
            $order = $this->makeOrder($customer, $product, $status);

            $this->postJson("/api/orders/{$order->id}/archive")
                ->assertStatus(400);

            $this->assertNull($order->fresh()->archived_at);
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_duplicate_archive_requests_are_rejected(): void
    {
        $admin = $this->makeUser('sysadmin-dupe@example.com', 'system_admin');
        $seller = $this->makeUser('seller-dupe@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-dupe@example.com', 'customer');
        $product = $this->makeProduct($seller);
        $order = $this->makeOrder($customer, $product, 'cancelled');

        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/archive")->assertOk();

        $archivedAt = $order->fresh()->archived_at;

        $this->postJson("/api/orders/{$order->id}/archive")->assertStatus(400);

        $this->assertTrue($archivedAt->equalTo($order->fresh()->archived_at));
    }

    public function test_archived_orders_remain_visible_to_customers_and_sellers(): void
    {
        $admin = $this->makeUser('sysadmin-visibility@example.com', 'system_admin');
        $seller = $this->makeUser('seller-visibility@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-visibility@example.com', 'customer');
        $product = $this->makeProduct($seller);
        $order = $this->makeOrder($customer, $product, 'cancelled');

        Sanctum::actingAs($admin);
        $this->postJson("/api/orders/{$order->id}/archive")->assertOk();

        // Customer still sees the archived order in list and detail.
        Sanctum::actingAs($customer);
        $customerIds = collect($this->getJson('/api/orders')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($order->id, $customerIds);
        $this->getJson("/api/orders/{$order->id}")->assertOk();

        // Seller still sees the archived order in list and detail.
        Sanctum::actingAs($seller);
        $sellerIds = collect($this->getJson('/api/orders')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($order->id, $sellerIds);
        $this->getJson("/api/orders/{$order->id}")->assertOk();
    }

    public function test_admin_lists_exclude_archived_orders_but_stats_still_count_them(): void
    {
        $admin = $this->makeUser('sysadmin-lists@example.com', 'system_admin');
        $seller = $this->makeUser('seller-lists@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-lists@example.com', 'customer');
        $product = $this->makeProduct($seller);

        $active = $this->makeOrder($customer, $product, 'cancelled');
        $archived = $this->makeOrder($customer, $product, 'cancelled');

        Sanctum::actingAs($admin);
        $this->postJson("/api/orders/{$archived->id}/archive")->assertOk();

        // Admin order list hides the archived order.
        $adminIds = collect($this->getJson('/api/orders')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $adminIds);
        $this->assertNotContains($archived->id, $adminIds);

        // /admin/orders/stats/all: recent_orders hides it, counts still include it.
        $stats = $this->getJson('/api/admin/orders/stats/all')->assertOk();
        $recentIds = collect($stats->json('recent_orders'))->pluck('id')->all();
        $this->assertNotContains($archived->id, $recentIds);
        $this->assertSame(2, $stats->json('total_orders'));
        $this->assertSame(2, $stats->json('status_breakdown.cancelled'));

        // /admin/dashboard: recent_orders hides it, totals still include it.
        $dashboard = $this->getJson('/api/admin/dashboard')->assertOk();
        $dashboardRecentIds = collect($dashboard->json('recent_orders'))->pluck('id')->all();
        $this->assertNotContains($archived->id, $dashboardRecentIds);
        $this->assertSame(2, $dashboard->json('total_orders'));
    }

    public function test_orders_cannot_be_permanently_deleted_via_the_removed_delete_route(): void
    {
        $systemAdmin = $this->makeUser('sysadmin-nodelete@example.com', 'system_admin');
        $admin = $this->makeUser('admin-nodelete@example.com', 'admin');
        $customer = $this->makeUser('buyer-nodelete@example.com', 'customer');
        $seller = $this->makeUser('seller-nodelete@example.com', 'seller', 'approved');
        $product = $this->makeProduct($seller);
        $order = $this->makeOrder($customer, $product, 'cancelled');

        // The DELETE route is gone: no role can permanently delete an order.
        foreach ([$systemAdmin, $admin, $customer, $seller] as $actor) {
            Sanctum::actingAs($actor);
            $this->deleteJson("/api/orders/{$order->id}")
                ->assertStatus(405);
        }

        // Nothing was deleted: the order and its items remain fully intact.
        $this->assertNotNull(Order::find($order->id));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNull($order->fresh()->archived_at);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_migration_is_a_no_op_when_archived_at_already_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'archived_at'));

        $migration = include database_path('migrations/2026_10_20_000001_add_archived_at_to_orders_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('orders', 'archived_at'));
    }

    public function test_migration_adds_archived_at_when_missing(): void
    {
        Schema::table('orders', function ($table) {
            $table->dropColumn('archived_at');
        });
        $this->assertFalse(Schema::hasColumn('orders', 'archived_at'));

        $migration = include database_path('migrations/2026_10_20_000001_add_archived_at_to_orders_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('orders', 'archived_at'));
    }
}
