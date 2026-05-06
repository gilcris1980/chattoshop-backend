<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;

// Public Routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    
    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
    
    // Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('role:super_admin,seller');
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->middleware('role:super_admin,seller');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->middleware('role:super_admin,seller');
    
    // Products
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/my', [ProductController::class, 'myProducts']);
    Route::post('/products', [ProductController::class, 'store'])->middleware('role:seller,super_admin');
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    
    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus'])->middleware('role:super_admin,seller');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::get('/orders/stats', [OrderController::class, 'stats']);
    
    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    
    // User Management (Super Admin only)
    Route::get('/users', [UserController::class, 'index'])->middleware('role:super_admin');
    Route::post('/users', [UserController::class, 'store'])->middleware('role:super_admin');
    Route::get('/users/{id}', [UserController::class, 'show'])->middleware('role:super_admin');
    Route::put('/users/{id}', [UserController::class, 'update'])->middleware('role:super_admin');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->middleware('role:super_admin');
    Route::get('/users/stats', [UserController::class, 'stats'])->middleware('role:super_admin');
});
