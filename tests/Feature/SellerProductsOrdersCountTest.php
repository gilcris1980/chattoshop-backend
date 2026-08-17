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

class SellerProductsOrdersCountTest extends TestCase
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
            $table->foreignId('category_id')->nullable()->constrained('categories');
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

        Schema::create('categories', function ($table) {
            $table->id();
            $table->string('name');
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

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => $role . ' User',
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => $role,
            'seller_status' => $role === 'seller' ? 'approved' : null,
        ]);
    }

    private function makeVerifiedCustomer(string $email): User
    {
        $customer = $this->makeUser($email, 'customer');
        $customer->forceFill(['email_verified_at' => now()])->save();

        return $customer;
    }

    public function test_seller_products_orders_column_reflects_orders(): void
    {
        $sellerA = $this->makeUser('sellerA@example.com', 'seller');
        $sellerB = $this->makeUser('sellerB@example.com', 'seller');
        $customer = $this->makeUser('customer@example.com', 'customer');

        $mouse = Product::create([
            'seller_id' => $sellerA->id,
            'name' => 'Mouse',
            'slug' => 'mouse',
            'price' => 199.99,
            'stock' => 10,
            'status' => true,
            'product_status' => 'approved',
        ]);

        $keyboard = Product::create([
            'seller_id' => $sellerA->id,
            'name' => 'Keyboard',
            'slug' => 'keyboard',
            'price' => 499.00,
            'stock' => 5,
            'status' => true,
            'product_status' => 'approved',
        ]);

        $lamp = Product::create([
            'seller_id' => $sellerB->id,
            'name' => 'Lamp',
            'slug' => 'lamp',
            'price' => 250.00,
            'stock' => 3,
            'status' => true,
            'product_status' => 'approved',
        ]);

        // Confirmed scenario: customer orders 1 unit of Mouse (stock stays as recorded)
        $order = Order::create([
            'user_id' => $customer->id,
            'total_amount' => 199.99,
            'status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $mouse->id,
            'quantity' => 1,
            'price' => 199.99,
        ]);

        Sanctum::actingAs($sellerA);

        $response = $this->getJson('/api/products/my');

        $response->assertOk();

        $products = collect($response->json());

        $this->assertSame($products->where('name', 'Mouse')->count(), 1);
        $mousePayload = $products->firstWhere('name', 'Mouse');
        $keyboardPayload = $products->firstWhere('name', 'Keyboard');
        $this->assertNotNull($mousePayload);
        $this->assertNotNull($keyboardPayload);

        // The Orders value for the ordered product must now be 1, not 0
        $this->assertSame(1, $mousePayload['order_items_count']);
        $this->assertArrayHasKey('order_items_count', $mousePayload);

        // A product with no orders must still show 0
        $this->assertSame(0, $keyboardPayload['order_items_count']);

        // Seller B's product must not appear in seller A's list
        $this->assertNull($products->firstWhere('name', 'Lamp'));

        // Stock and price remain untouched by the fix
        $this->assertSame(10, (int) $mousePayload['stock']);
        $this->assertSame('199.99', (string) $mousePayload['price']);

        // Accessor fallback still works when accessing the attribute directly
        $this->assertSame(1, $mouse->fresh()->order_items_count);

        // One customer order is counted exactly once (row-based metric)
        $this->assertSame(1, OrderItem::where('product_id', $mouse->id)->count());
    }

    public function test_second_order_increments_count_and_seller_isolation_holds(): void
    {
        $sellerA = $this->makeUser('sellerA2@example.com', 'seller');
        $sellerB = $this->makeUser('sellerB2@example.com', 'seller');
        $customer = $this->makeUser('customer2@example.com', 'customer');

        $mouse = Product::create([
            'seller_id' => $sellerA->id,
            'name' => 'Mouse',
            'slug' => 'mouse',
            'price' => 199.99,
            'stock' => 10,
            'status' => true,
            'product_status' => 'approved',
        ]);

        $lamp = Product::create([
            'seller_id' => $sellerB->id,
            'name' => 'Lamp',
            'slug' => 'lamp',
            'price' => 250.00,
            'stock' => 3,
            'status' => true,
            'product_status' => 'approved',
        ]);

        foreach ([1, 2] as $quantity) {
            $order = Order::create([
                'user_id' => $customer->id,
                'total_amount' => 199.99 * $quantity,
                'status' => 'pending',
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $mouse->id,
                'quantity' => $quantity,
                'price' => 199.99,
            ]);
        }

        // Order for seller B's product by the same customer
        $orderB = Order::create([
            'user_id' => $customer->id,
            'total_amount' => 250.00,
            'status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $orderB->id,
            'product_id' => $lamp->id,
            'quantity' => 1,
            'price' => 250.00,
        ]);

        Sanctum::actingAs($sellerA);

        $response = $this->getJson('/api/products/my');
        $response->assertOk();

        $products = collect($response->json());

        $mousePayload = $products->firstWhere('name', 'Mouse');
        $this->assertNotNull($mousePayload);

        // Two separate orders containing Mouse -> count 2 (rows, not quantities summed)
        $this->assertSame(2, $mousePayload['order_items_count']);

        // Seller A sees no trace of seller B's product
        $this->assertNull($products->firstWhere('name', 'Lamp'));

        // Seller B's own list correctly counts only their own orders
        Sanctum::actingAs($sellerB);

        $sellerBProducts = collect($this->getJson('/api/products/my')->json());
        $lampPayload = $sellerBProducts->firstWhere('name', 'Lamp');
        $this->assertNotNull($lampPayload);
        $this->assertSame(1, $lampPayload['order_items_count']);
        $this->assertNull($sellerBProducts->firstWhere('name', 'Mouse'));
    }

    public function test_cancelled_order_no_longer_counts_and_stock_is_restored(): void
    {
        $seller = $this->makeUser('sellerC@example.com', 'seller');
        $customer = $this->makeVerifiedCustomer('customerC@example.com');

        $mouse = Product::create([
            'seller_id' => $seller->id,
            'name' => 'Mouse',
            'slug' => 'mouse',
            'price' => 199.99,
            'stock' => 10,
            'status' => true,
            'product_status' => 'approved',
        ]);

        // Order placed (checkout would have decremented stock to 9)
        $order = Order::create([
            'user_id' => $customer->id,
            'total_amount' => 199.99,
            'status' => 'pending',
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $mouse->id,
            'quantity' => 1,
            'price' => 199.99,
        ]);

        $mouse->decrement('stock', 1);

        // Seller sees Orders = 1 while the order is valid
        Sanctum::actingAs($seller);
        $sellerView = collect($this->getJson('/api/products/my')->json());
        $this->assertSame(1, $sellerView->firstWhere('name', 'Mouse')['order_items_count']);

        // Customer cancels the order through the real endpoint
        Sanctum::actingAs($customer);
        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk();

        // Stock is restored to 10 (existing restoration behavior untouched)
        $this->assertSame(10, $mouse->fresh()->stock);

        // Cancelled order remains as historical record
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNotNull(OrderItem::find($orderItem->id));

        // Seller Orders count is back to 0
        Sanctum::actingAs($seller);
        $sellerViewAfter = collect($this->getJson('/api/products/my')->json());
        $this->assertSame(0, $sellerViewAfter->firstWhere('name', 'Mouse')['order_items_count']);
    }

    public function test_cancelling_one_of_multiple_orders_reduces_count_by_exactly_one(): void
    {
        $seller = $this->makeUser('sellerD@example.com', 'seller');
        $customer = $this->makeVerifiedCustomer('customerD@example.com');

        $mouse = Product::create([
            'seller_id' => $seller->id,
            'name' => 'Mouse',
            'slug' => 'mouse',
            'price' => 199.99,
            'stock' => 10,
            'status' => true,
            'product_status' => 'approved',
        ]);

        $orderOne = Order::create(['user_id' => $customer->id, 'total_amount' => 199.99, 'status' => 'pending']);
        OrderItem::create(['order_id' => $orderOne->id, 'product_id' => $mouse->id, 'quantity' => 1, 'price' => 199.99]);

        $orderTwo = Order::create(['user_id' => $customer->id, 'total_amount' => 199.99, 'status' => 'pending']);
        OrderItem::create(['order_id' => $orderTwo->id, 'product_id' => $mouse->id, 'quantity' => 1, 'price' => 199.99]);

        $mouse->decrement('stock', 2);

        Sanctum::actingAs($seller);
        $sellerView = collect($this->getJson('/api/products/my')->json());
        $this->assertSame(2, $sellerView->firstWhere('name', 'Mouse')['order_items_count']);

        // Cancel only the first order
        Sanctum::actingAs($customer);
        $this->postJson("/api/orders/{$orderOne->id}/cancel")->assertOk();

        $this->assertSame(9, $mouse->fresh()->stock);

        Sanctum::actingAs($seller);
        $sellerViewAfter = collect($this->getJson('/api/products/my')->json());
        $this->assertSame(1, $sellerViewAfter->firstWhere('name', 'Mouse')['order_items_count']);
    }
}