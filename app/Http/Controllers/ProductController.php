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
        $query = Product::with(['seller', 'category'])
            ->where('status', true)
            ->where('product_status', 'approved');

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

    public function adminProducts(Request $request)
    {
        $query = Product::with(['seller', 'category']);

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->product_status) {
            $query->where('product_status', $request->product_status);
        }

        if ($request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->category) {
            $query->where('category_id', $request->category);
        }

        $sortBy = $request->sort ?? 'created_at';
        $sortDir = $request->order ?? 'desc';

        if (in_array($sortBy, ['price', 'created_at', 'name', 'product_status'])) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $products = $query->paginate($request->per_page ?? 15);

        return response()->json($products);
    }

    private function authorizeSellerNotPending($user): void
    {
        if ($user->role === 'seller' && $user->seller_status === 'pending') {
            abort(403, 'Your account is pending administrator approval.');
        }
    }

  public function store(Request $request)
{
    try {
        $this->authorizeSellerNotPending($request->user());

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
        $data['product_status'] = 'pending';

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

    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        throw $e;
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

        $user = auth('sanctum')->user();

        if (
            $product->product_status !== 'approved' ||
            !$product->status
        ) {
            if (!$user || ($user->id !== $product->seller_id && !$user->isAdmin())) {
                return response()->json(['message' => 'Product not found'], 404);
            }
        }

        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeSellerNotPending($request->user());

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
            if ($product->image && str_starts_with($product->image, 'https://res.cloudinary.com/')) {
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
        $user = auth()->user();
        $this->authorizeSellerNotPending($user);

        $product = Product::find($id);

        if (!$product) {

            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        $imageDeleted = false;

        // ADMIN DELETE
        if (
            $user->role === 'system_admin' ||
            $user->role === 'admin'
        ) {

            if ($product->image && str_starts_with($product->image, 'https://res.cloudinary.com/')) {
                cloudinary()->uploadApi()->destroy($this->extractCloudinaryPublicId($product->image));
                $imageDeleted = true;
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

            if (!$imageDeleted && $product->image && str_starts_with($product->image, 'https://res.cloudinary.com/')) {
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

    public function approveProduct($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['product_status' => 'approved']);

        return response()->json([
            'message' => 'Product approved successfully',
            'product' => $product->fresh()->load(['seller', 'category']),
        ]);
    }

    public function rejectProduct($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['product_status' => 'rejected']);

        return response()->json([
            'message' => 'Product rejected successfully',
            'product' => $product->fresh()->load(['seller', 'category']),
        ]);
    }
}