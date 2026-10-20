<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerOrderCancelAndSortTest extends TestCase
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

    private function makeProduct(User $seller, string $name, int $stock = 10): Product
    {
        return Product::create([
            'seller_id' => $seller->id,
            'name' => $name,
            'slug' => strtolower($name) . '-' . $seller->id,
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

    public function test_seller_can_cancel_own_pending_order(): void
    {
        $seller = $this->makeUser('seller-cancel@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-cancel@example.com', 'customer');
        $product = $this->makeProduct($seller, 'Mouse', 10);
        $order = $this->makeOrder($customer, $product, 'pending');
        $product->decrement('stock', 1);

        Sanctum::actingAs($seller);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertTrue(
            Notification::where('user_id', $customer->id)
                ->where('title', 'Order Cancelled')
                ->exists()
        );
    }

    public function test_seller_cannot_cancel_non_pending_order(): void
    {
        $seller = $this->makeUser('seller-nopending@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-nopending@example.com', 'customer');
        $product = $this->makeProduct($seller, 'Keyboard');
        $order = $this->makeOrder($customer, $product, 'processing');

        Sanctum::actingAs($seller);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(400);

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_seller_cannot_cancel_another_sellers_order(): void
    {
        $sellerA = $this->makeUser('seller-a@example.com', 'seller', 'approved');
        $sellerB = $this->makeUser('seller-b@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-cross@example.com', 'customer');
        $productB = $this->makeProduct($sellerB, 'Lamp');
        $order = $this->makeOrder($customer, $productB, 'pending');

        Sanctum::actingAs($sellerA);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_seller_cannot_cancel_order_containing_another_sellers_items(): void
    {
        $sellerA = $this->makeUser('seller-mixed-a@example.com', 'seller', 'approved');
        $sellerB = $this->makeUser('seller-mixed-b@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-mixed@example.com', 'customer');
        $productA = $this->makeProduct($sellerA, 'Mouse');
        $productB = $this->makeProduct($sellerB, 'Lamp');

        // Mixed-seller order (should never exist with current checkout grouping,
        // but cancellation must refuse it regardless).
        $order = $this->makeOrder($customer, $productA, 'pending');
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $productB->id,
            'quantity' => 1,
            'price' => 199.99,
        ]);

        Sanctum::actingAs($sellerA);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_duplicate_cancellation_is_rejected(): void
    {
        $seller = $this->makeUser('seller-dupe@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-dupe@example.com', 'customer');
        $product = $this->makeProduct($seller, 'Mouse');
        $order = $this->makeOrder($customer, $product, 'pending');

        Sanctum::actingAs($seller);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(400);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_customer_cancellation_behaviour_is_unchanged(): void
    {
        $seller = $this->makeUser('seller-keep@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-keep@example.com', 'customer');
        $otherCustomer = $this->makeUser('buyer-other@example.com', 'customer');
        $product = $this->makeProduct($seller, 'Mouse', 10);
        $order = $this->makeOrder($customer, $product, 'pending');
        $product->decrement('stock', 1);

        // Owner cancels: still works exactly as before.
        Sanctum::actingAs($customer);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);

        // A different customer still gets 403 on someone else's order.
        $orderTwo = $this->makeOrder($customer, $product, 'pending');
        Sanctum::actingAs($otherCustomer);
        $this->postJson("/api/orders/{$orderTwo->id}/cancel")->assertStatus(403);
        $this->assertSame('pending', $orderTwo->fresh()->status);
    }

    public function test_seller_order_list_sorts_by_status_priority_then_newest_first(): void
    {
        $seller = $this->makeUser('seller-sort@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-sort@example.com', 'customer');
        $product = $this->makeProduct($seller, 'Mouse');

        $base = Carbon::parse('2026-01-01 00:00:00');

        $seed = [
            ['cancelled', 0],
            ['completed', 10],
            ['delivered', 20],
            ['shipped', 30],
            ['processing', 40],
            ['pending', 50], // older pending
            ['pending', 60], // newer pending
        ];

        foreach ($seed as [$status, $minutes]) {
            $order = $this->makeOrder($customer, $product, $status);
            $order->created_at = $base->copy()->addMinutes($minutes);
            $order->save();
        }

        Sanctum::actingAs($seller);

        $statuses = collect($this->getJson('/api/orders')->assertOk()->json('data'))
            ->pluck('status')
            ->all();

        $this->assertSame(
            ['pending', 'pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'],
            $statuses
        );

        // Newest first inside the same status (the two pending orders).
        $pendingCreated = collect($this->getJson('/api/orders')->json('data'))
            ->where('status', 'pending')
            ->pluck('created_at')
            ->all();

        $this->assertTrue(strtotime($pendingCreated[0]) >= strtotime($pendingCreated[1]));
    }

    public function test_customer_order_sorting_is_untouched(): void
    {
        $seller = $this->makeUser('seller-custsort@example.com', 'seller', 'approved');
        $customer = $this->makeUser('buyer-custsort@example.com', 'customer');
        $product = $this->makeProduct($seller, 'Mouse');

        // Newest first for customers, no status priority applied:
        // the newest order here is the cancelled one, which customers still see first.
        $pendingOld = $this->makeOrder($customer, $product, 'pending');
        $pendingOld->created_at = Carbon::parse('2026-01-01 00:00:00');
        $pendingOld->save();

        $cancelledNew = $this->makeOrder($customer, $product, 'cancelled');
        $cancelledNew->created_at = Carbon::parse('2026-02-01 00:00:00');
        $cancelledNew->save();

        Sanctum::actingAs($customer);

        $statuses = collect($this->getJson('/api/orders')->assertOk()->json('data'))
            ->pluck('status')
            ->all();

        $this->assertSame(['cancelled', 'pending'], $statuses);
    }
}
