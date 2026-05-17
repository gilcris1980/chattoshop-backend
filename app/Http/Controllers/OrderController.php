<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $orders = Order::with(['user', 'items.product'])
                ->orderBy('created_at', 'desc')
                ->paginate(10);
        } elseif ($user->isSeller()) {
            $sellerProductIds = Product::where('seller_id', $user->id)->pluck('id');
            $orderIds = OrderItem::whereIn('product_id', $sellerProductIds)->pluck('order_id')->unique();
            
            $orders = Order::with(['user', 'items.product'])
                ->whereIn('id', $orderIds)
                ->orderBy('created_at', 'desc')
                ->paginate(10);
        } else {
            $orders = Order::with(['items.product'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(10);
        }

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_address' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $totalAmount = 0;
        $orderItems = [];

        foreach ($request->items as $item) {
            $product = Product::findOrFail($item['product_id']);
            
            if ($product->stock < $item['quantity']) {
                return response()->json([
                    'message' => "Insufficient stock for product: {$product->name}"
                ], 400);
            }

            // Check if user is buying their own product
            if ($product->seller_id === $request->user()->id) {
                return response()->json([
                    'message' => "You cannot purchase your own product: {$product->name}"
                ], 400);
            }

            $totalAmount += $product->price * $item['quantity'];
            $orderItems[] = [
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'price' => $product->price,
            ];

            $product->decrement('stock', $item['quantity']);
        }

        $order = Order::create([
            'user_id' => $request->user()->id,
            'total_amount' => $totalAmount,
            'shipping_address' => $request->shipping_address,
            'payment_method' => 'cod',
            'notes' => $request->notes,
            'status' => 'pending',
        ]);

        foreach ($orderItems as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);
        }

        $this->createNotification($request->user()->id, 'Order Placed', 'Your order #' . $order->id . ' has been placed successfully.', 'order');

        $sellerIds = Product::whereIn('id', collect($orderItems)->pluck('product_id'))->pluck('seller_id')->unique();
        foreach ($sellerIds as $sellerId) {
            $this->createNotification($sellerId, 'New Order', 'You have a new order #' . $order->id, 'order');
        }

        return response()->json([
            'order' => $order->load(['items.product']),
            'message' => 'Order created successfully'
        ], 201);
    }

    public function show($id)
    {
        $order = Order::with(['user', 'items.product'])->findOrFail($id);
        return response()->json($order);
    }

    public function updateStatus(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::findOrFail($id);
        
        // Order status flow: pending → processing → shipped → delivered → completed
        $allowedStatuses = ['pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'];
        
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:' . implode(',', $allowedStatuses),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        if ($user->role === 'customer') {
            return response()->json(['message' => 'Customers cannot update order status'], 403);
        }
        
        if ($user->isSeller()) {
            $sellerProductIds = Product::where('seller_id', $user->id)->pluck('id');
            $orderHasSellerProducts = OrderItem::where('order_id', $order->id)
                ->whereIn('product_id', $sellerProductIds)
                ->exists();
            
            if (!$orderHasSellerProducts) {
                return response()->json(['message' => 'Unauthorized - This order does not contain your products'], 403);
            }
        }

        $oldStatus = $order->status;

       \Log::info($request->all());

        $order->status = $request->status;
        $order->save();

        if ($request->status !== $oldStatus) {
            $statusMessages = [
                'processing' => 'Your order is being processed',
                'shipped' => 'Your order has been shipped',
                'delivered' => 'Your order has been delivered',
                'completed' => 'Your order has been completed',
                'cancelled' => 'Your order has been cancelled',
            ];

            if (isset($statusMessages[$request->status])) {
                $this->createNotification(
                    $order->user_id,
                    'Order Update',
                    'Order #' . $order->id . ': ' . $statusMessages[$request->status],
                    'order'
                );
            }

            if ($request->status === 'cancelled') {
                foreach ($order->items as $item) {
                    $item->product->increment('stock', $item->quantity);
                }
            }
        }

        return response()->json([
            'order' => $order->load(['user', 'items.product']),
            'message' => 'Order status updated successfully'
        ]);
    }

    public function cancel($id)
    {
        $order = Order::findOrFail($id);

        // Only the order owner can cancel
        if ($order->user_id !== request()->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Only pending orders can be cancelled by customer
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Cannot cancel order with status: ' . $order->status . '. Only pending orders can be cancelled.'], 400);
        }

        $order->update(['status' => 'cancelled']);

        foreach ($order->items as $item) {
            $item->product->increment('stock', $item->quantity);
        }

        $this->createNotification(
            $order->user_id,
            'Order Cancelled',
            'Order #' . $order->id . ' has been cancelled',
            'order'
        );

        return response()->json(['message' => 'Order cancelled successfully']);
    }

    private function createNotification($userId, $title, $message, $type)
    {
        Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'is_read' => false,
        ]);
    }

    public function allStats(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return response()->json([
            'total_orders' => Order::count(),
            'total_revenue' => Order::whereIn('status', ['delivered', 'completed'])->sum('total_amount'),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'status_breakdown' => [
                'pending' => Order::where('status', 'pending')->count(),
                'processing' => Order::where('status', 'processing')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'delivered' => Order::where('status', 'delivered')->count(),
                'completed' => Order::where('status', 'completed')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
            ],
            'recent_orders' => Order::with('user')->latest()->limit(10)->get(),
        ]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json([
                'total_orders' => Order::count(),
                'total_sales' => Order::whereIn('status', ['delivered', 'completed'])->sum('total_amount'),
                'pending_orders' => Order::where('status', 'pending')->count(),
                'recent_orders' => Order::with('user')->latest()->limit(5)->get(),
            ]);
        } elseif ($user->isSeller()) {
            $sellerProductIds = Product::where('seller_id', $user->id)->pluck('id');
            $orderItems = OrderItem::whereIn('product_id', $sellerProductIds)->get();
            
            return response()->json([
                'total_orders' => $orderItems->unique('order_id')->count(),
                'total_sales' => $orderItems->sum(function($item) {
                    return $item->price * $item->quantity;
                }),
                'pending_orders' => Order::whereHas('items', function($q) use ($sellerProductIds) {
                    $q->whereIn('product_id', $sellerProductIds);
                })->where('status', 'pending')->count(),
            ]);
        }

        return response()->json([
            'total_orders' => Order::where('user_id', $user->id)->count(),
            'total_spent' => Order::where('user_id', $user->id)->whereIn('status', ['delivered', 'completed'])->sum('total_amount'),
        ]);
    }
}