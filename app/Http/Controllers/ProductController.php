<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['seller', 'category']);

        if ($request->category) {
            $query->where('category_id', $request->category);
        }

        if ($request->seller) {
            $query->where('seller_id', $request->seller);
        }

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->min_price && $request->max_price) {
            $query->whereBetween('price', [$request->min_price, $request->max_price]);
        }

        if ($request->status !== null) {
            $query->where('status', $request->status);
        }

        $sortBy = $request->sort ?? 'created_at';
        $sortDir = $request->order ?? 'desc';

        if (in_array($sortBy, ['price', 'created_at', 'name'])) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $products = $query->paginate($request->per_page ?? 12);

        return response()->json($products);
    }
     public function myProducts(Request $request)
            {
             $products = Product::with(['seller', 'category'])
            ->where('seller_id', $request->user()->id)
            ->latest()
            ->get();

            return response()->json($products);
          }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'category_id', 'description', 'price', 'stock', 'status']);
        $data['slug'] = Str::slug($request->name);
        $data['seller_id'] = $request->user()->id;

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $filename = time() . '_' . $image->getClientOriginalName();
            $path = $image->storeAs('products', $filename, 'public');
            $data['image'] = $path;
        }

        $product = Product::create($data);

        return response()->json([
            'product' => $product->load(['seller', 'category']),
            'message' => 'Product created successfully'
        ], 201);
    }

    public function show($id)
    {
        $product = Product::with(['seller', 'category'])->findOrFail($id);
        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($request->user()->id !== $product->seller_id && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'stock' => 'sometimes|integer|min:0',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'category_id', 'description', 'price', 'stock', 'status']);

        if ($request->name) {
            $data['slug'] = Str::slug($request->name);
        }

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $image = $request->file('image');
            $filename = time() . '_' . $image->getClientOriginalName();
            $path = $image->storeAs('products', $filename, 'public');
            $data['image'] = $path;
        }

        $product->update($data);

        return response()->json([
            'product' => $product->load(['seller', 'category']),
            'message' => 'Product updated successfully'
        ]);
    }

public function destroy($id)
{

    $product = Product::find($id);

    if (!$product) {

        return response()->json([
            'message' => 'Product not found'
        ], 404);

    }

    $user = auth()->user();

    // ADMIN CAN DELETE ANY PRODUCT
    if (
        $user->role === 'system_admin' ||
        $user->role === 'admin'
    ) {

        if ($product->image) {

            Storage::disk('public')
                ->delete($product->image);

        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ]);

    }

    // SELLER CAN DELETE OWN PRODUCT
    if (
        $user->role === 'seller' &&
        $product->seller_id == $user->id
    ) {

        if ($product->image) {

            Storage::disk('public')
                ->delete($product->image);

        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ]);

    }

    return response()->json([
        'message' => 'Unauthorized'
    ], 403);

}

}