<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    // Public - for listing users (without sensitive data)
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
        }

        if ($request->role) {
            $query->where('role', $request->role);
        }

        $users = $query->select('id', 'name', 'email', 'role', 'phone', 'address', 'avatar', 'created_at', 'updated_at')
                       ->orderBy('created_at', 'desc')
                       ->paginate(10);

        return response()->json($users);
    }

    // Admin Dashboard Stats
    public function dashboard(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'total_users' => User::count(),
            'total_orders' => Order::count(),
            'total_revenue' => Order::whereIn('status', ['delivered', 'completed'])->sum('total_amount'),
            'total_products' => Product::count(),
            'total_categories' => Category::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'recent_orders' => Order::with('user')->orderBy('created_at', 'desc')->limit(10)->get(),
            'recent_users' => User::orderBy('created_at', 'desc')->limit(10)->get(),
            'order_stats' => [
                'pending' => Order::where('status', 'pending')->count(),
                'processing' => Order::where('status', 'processing')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'delivered' => Order::where('status', 'delivered')->count(),
                'completed' => Order::where('status', 'completed')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
            ],
            'user_stats' => [
                'customers' => User::where('role', 'customer')->count(),
                'sellers' => User::where('role', 'seller')->count(),
                'admins' => User::where('role', 'admin')->count(),
                'system_admins' => User::where('role', 'system_admin')->count(),
            ],
        ]);
    }

    // Admin - List all users with full details
    public function adminIndex(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = User::query();

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->role) {
            $query->where('role', $request->role);
        }

        $users = $query->select('id', 'name', 'email', 'role', 'phone', 'address', 'avatar', 'created_at', 'updated_at')
                       ->orderBy('created_at', 'desc')
                       ->paginate($request->per_page ?? 15);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        
        // Only system_admin can create users
        if (!$user->isSystemAdmin()) {
            return response()->json(['message' => 'Only System Admin can create users'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:system_admin,admin,seller,customer',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Prevent creating another system_admin by regular admin
        if ($request->role === 'system_admin') {
            return response()->json(['message' => 'Cannot create another System Admin'], 403);
        }

        $newUser = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        return response()->json([
            'user' => $newUser,
            'message' => 'User created successfully'
        ], 201);
    }

    public function show($id)
    {
        $user = User::findOrFail($id);
        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();
        
        if (!$currentUser->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $targetUser = User::findOrFail($id);

        // System Admin protection - cannot be edited by anyone including other admins
        if ($targetUser->isSystemAdmin()) {
            // Only the system admin themselves can update their own profile (except role)
            if ($currentUser->id !== $targetUser->id) {
                return response()->json(['message' => 'System Admin cannot be edited by other users'], 403);
            }
            // System Admin can update their own name, email, phone, address, but NOT role
            if ($request->has('role')) {
                return response()->json(['message' => 'System Admin cannot change their own role'], 403);
            }
        }

        // Only system_admin can change other admin's roles
        if ($request->has('role') && $targetUser->role === 'admin' && !$currentUser->isSystemAdmin()) {
            return response()->json(['message' => 'Only System Admin can change Admin roles'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:8',
            'role' => 'sometimes|in:admin,seller,customer',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'email', 'phone', 'address']);
        
        // Only system_admin can change roles
        if ($request->has('role') && $currentUser->isSystemAdmin()) {
            $data['role'] = $request->role;
        }
        
        if ($request->password) {
            $data['password'] = Hash::make($request->password);
        }

        $targetUser->update($data);

        return response()->json([
            'user' => $targetUser->fresh(),
            'message' => 'User updated successfully'
        ]);
    }

    public function updateRole(Request $request, $id)
    {
        $currentUser = $request->user();
        
        // Only system_admin can update roles
        if (!$currentUser->isSystemAdmin()) {
            return response()->json(['message' => 'Only System Admin can update roles'], 403);
        }

        $targetUser = User::findOrFail($id);

        // System Admin protection - cannot change own role
        if ($targetUser->isSystemAdmin()) {
            return response()->json(['message' => 'System Admin role cannot be changed'], 403);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'required|in:admin,seller,customer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $targetUser->update(['role' => $request->role]);

        return response()->json([
            'user' => $targetUser->fresh(),
            'message' => 'Role updated successfully'
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();
        
        if (!$currentUser->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $targetUser = User::findOrFail($id);

        // System Admin protection - cannot be deleted
        if ($targetUser->isSystemAdmin()) {
            return response()->json(['message' => 'System Admin cannot be deleted'], 403);
        }

        // Cannot delete yourself
        if ($targetUser->id === $currentUser->id) {
            return response()->json(['message' => 'Cannot delete your own account'], 403);
        }

        // Cannot delete other system admin
        if ($targetUser->isSystemAdmin()) {
            return response()->json(['message' => 'System Admin cannot be deleted'], 403);
        }

        // Admin can only delete sellers and customers
        if ($currentUser->role === 'admin' && in_array($targetUser->role, ['admin', 'system_admin'])) {
            return response()->json(['message' => 'Only System Admin can delete other admins'], 403);
        }

        // Delete avatar if exists
        if ($targetUser->avatar) {
            Storage::disk('public')->delete($targetUser->avatar);
        }

        // Delete user's tokens
        $targetUser->tokens()->delete();

        // Delete user
        $targetUser->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function stats()
    {
        return response()->json([
            'total_users' => User::count(),
            'total_sellers' => User::where('role', 'seller')->count(),
            'total_customers' => User::where('role', 'customer')->count(),
            'total_admins' => User::where('role', 'admin')->count(),
            'total_system_admins' => User::where('role', 'system_admin')->count(),
        ]);
    }
}