<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query();

        if ($request->status !== null) {
            $query->where('status', $request->status);
        }

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $categories = $query->orderBy('name')->get();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string',
            'status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category = Category::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'status' => $request->status ?? true,
        ]);

        return response()->json([
            'category' => $category,
            'message' => 'Category created successfully'
        ], 201);
    }

    public function show($id)
    {
        $category = Category::with('products')->findOrFail($id);
        return response()->json($category);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string',
            'status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'description', 'status']);
        
        if ($request->name) {
            $data['slug'] = Str::slug($request->name);
        }

        $category->update($data);

        return response()->json([
            'category' => $category,
            'message' => 'Category updated successfully'
        ]);
    }

    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);

            if ($category->products()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete category because it contains products.'
                ], 422);
            }

            $category->delete();

            return response()->json(['message' => 'Category deleted successfully']);

        } catch (\Throwable $e) {
            Log::error('Category delete failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'category_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }
}
