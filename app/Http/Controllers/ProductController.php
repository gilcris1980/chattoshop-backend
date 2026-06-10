<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;


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
    try {

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
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name',
            'category_id',
            'description',
            'price',
            'stock',
            'status'
        ]);

        $data['seller_id'] = auth()->id();

        // FIX SLUG
        $data['slug'] = Str::slug($data['name']);

        // IMAGE UPLOAD
        if ($request->hasFile('image')) {
            $uploadedFile = cloudinary()->uploadApi()->upload(
                $request->file('image')->getRealPath(),
                ['folder' => 'products']
            );
            $data['image'] = $uploadedFile['secure_url'];
        }

        $product = Product::create($data);

        return response()->json([
            'message' => 'Product created successfully',
            'data' => $product
        ], 201);

    } catch (\Exception $e) {

        return response()->json([
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ], 500);
    }
}

    public function show($id)
    {
        $product = Product::with(['seller', 'category'])
            ->findOrFail($id);

        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if (
            $request->user()->id !== $product->seller_id &&
            !$request->user()->isAdmin()
        ) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
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
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name',
            'category_id',
            'description',
            'price',
            'stock',
            'status'
        ]);

        if ($request->name) {
            $data['slug'] = Str::slug($request->name);
        }

        // IMAGE UPDATE
        if ($request->hasFile('image')) {
            if ($product->image) {
                cloudinary()->uploadApi()->destroy($this->extractCloudinaryPublicId($product->image));
            }

            $uploadedFile = cloudinary()->uploadApi()->upload(
                $request->file('image')->getRealPath(),
                ['folder' => 'products']
            );
            $data['image'] = $uploadedFile['secure_url'];
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

        // ADMIN DELETE
        if (
            $user->role === 'system_admin' ||
            $user->role === 'admin'
        ) {

            if ($product->image) {
                cloudinary()->uploadApi()->destroy($this->extractCloudinaryPublicId($product->image));
            }

            $product->delete();

            return response()->json([
                'message' => 'Product deleted successfully'
            ]);
        }

        // SELLER DELETE OWN PRODUCT
        if (
            $user->role === 'seller' &&
            $product->seller_id == $user->id
        ) {

            if ($product->image) {
                cloudinary()->uploadApi()->destroy($this->extractCloudinaryPublicId($product->image));
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