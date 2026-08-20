<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    private function ensureCustomer($user): ?JsonResponse
    {
        if ($user->role !== 'customer') {
            return response()->json(['message' => 'Only customers can use the cart.'], 403);
        }
        return null;
    }

    private function ensureCart($user): Cart
    {
        return Cart::firstOrCreate(['user_id' => $user->id]);
    }

    public function show(Request $request)
    {
        $forbidden = $this->ensureCustomer($request->user());
        if ($forbidden) {
            return $forbidden;
        }

        $cart = $this->ensureCart($request->user());

        $items = CartItem::with('product')
            ->where('cart_id', $cart->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'cart' => $cart,
            'items' => $items->map(function (CartItem $item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'product' => $item->product,
                ];
            }),
        ]);
    }

    public function addItem(Request $request)
    {
        $forbidden = $this->ensureCustomer($request->user());
        if ($forbidden) {
            return $forbidden;
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            return DB::transaction(function () use ($request) {
                $cart = $this->ensureCart($request->user());

                $product = Product::whereKey($request->product_id)->lockForUpdate()->first();

                if ($product->product_status !== 'approved' || !$product->status) {
                    return response()->json([
                        'message' => "Product '{$product->name}' is not available for purchase"
                    ], 400);
                }

                if ($product->seller_id === $request->user()->id) {
                    return response()->json([
                        'message' => 'You cannot add your own product to the cart.'
                    ], 400);
                }

                $item = CartItem::where('cart_id', $cart->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                $newQuantity = ($item->quantity ?? 0) + (int) $request->quantity;

                if ($newQuantity > $product->stock) {
                    return response()->json([
                        'message' => 'Maximum available stock reached.'
                    ], 400);
                }

                if ($item) {
                    $item->quantity = $newQuantity;
                    $item->save();
                } else {
                    $item = CartItem::create([
                        'cart_id' => $cart->id,
                        'product_id' => $product->id,
                        'quantity' => $request->quantity,
                    ]);
                }

                return response()->json([
                    'message' => 'Item added to cart',
                    'item' => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                    ],
                ], 201);
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to add item to cart.'], 500);
        }
    }

    public function updateQuantity(Request $request, $productId)
    {
        $forbidden = $this->ensureCustomer($request->user());
        if ($forbidden) {
            return $forbidden;
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            return DB::transaction(function () use ($request, $productId) {
                $cart = $this->ensureCart($request->user());

                $product = Product::whereKey($productId)->lockForUpdate()->first();

                if (!$product) {
                    return response()->json(['message' => 'Product not found'], 404);
                }

                $item = CartItem::where('cart_id', $cart->id)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if (!$item) {
                    return response()->json(['message' => 'Item not found in cart'], 404);
                }

                if ((int) $request->quantity > $product->stock) {
                    return response()->json([
                        'message' => 'Maximum available stock reached.'
                    ], 400);
                }

                $item->quantity = (int) $request->quantity;
                $item->save();

                return response()->json([
                    'message' => 'Cart updated',
                    'item' => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to update cart.'], 500);
        }
    }

    public function removeItem(Request $request, $productId)
    {
        $forbidden = $this->ensureCustomer($request->user());
        if ($forbidden) {
            return $forbidden;
        }

        $cart = $this->ensureCart($request->user());

        $deleted = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Item not found in cart'], 404);
        }

        return response()->json(['message' => 'Item removed from cart']);
    }

    public function clear(Request $request)
    {
        $forbidden = $this->ensureCustomer($request->user());
        if ($forbidden) {
            return $forbidden;
        }

        $cart = $this->ensureCart($request->user());

        CartItem::where('cart_id', $cart->id)->delete();

        return response()->json(['message' => 'Cart cleared']);
    }
}