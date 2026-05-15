<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return response()->json([
        'message' => 'ChattoShop API',
        'version' => '1.0'
    ]);
});

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

// AUTH
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// PUBLIC PRODUCTS & CATEGORIES
Route::get('/categories', [CategoryController::class, 'index']);

Route::get('/products', [ProductController::class, 'index']);

// IMPORTANT:
// /products/my MUST BE BEFORE /products/{id}

Route::get('/products/{id}', [ProductController::class, 'show']);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTH USER
    |--------------------------------------------------------------------------
    */

    Route::get('/me', [AuthController::class, 'me']);

    Route::post('/logout', [AuthController::class, 'logout']);

    /*
    |--------------------------------------------------------------------------
    | PROFILE
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [ProfileController::class, 'show']);

    Route::post('/profile', [ProfileController::class, 'update']);

    Route::put('/profile', [ProfileController::class, 'update']);

    /*
    |--------------------------------------------------------------------------
    | PRODUCTS
    |--------------------------------------------------------------------------
    */

    Route::get('/products/my', [ProductController::class, 'myProducts']);

    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('role:seller,system_admin,admin');

    Route::put('/products/{id}', [ProductController::class, 'update']);

    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ORDERS
    |--------------------------------------------------------------------------
    */

    Route::get('/orders', [OrderController::class, 'index']);

    Route::post('/orders', [OrderController::class, 'store']);

    Route::get('/orders/{id}', [OrderController::class, 'show']);

    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus'])
        ->middleware('role:system_admin,admin,seller');

    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);

    Route::get('/orders/stats', [OrderController::class, 'stats']);

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);

    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ADMIN ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:system_admin,admin')
        ->prefix('admin')
        ->group(function () {

            Route::get('/dashboard', [UserController::class, 'dashboard']);

            Route::get('/users', [UserController::class, 'adminIndex']);

            Route::post('/users', [UserController::class, 'store']);

            Route::get('/users/{id}', [UserController::class, 'show']);

            Route::put('/users/{id}', [UserController::class, 'update']);

            Route::delete('/users/{id}', [UserController::class, 'destroy']);

            Route::put('/users/{id}/role', [UserController::class, 'updateRole']);

            Route::get('/users/stats', [UserController::class, 'stats']);

            Route::get('/orders/stats/all', [OrderController::class, 'allStats']);
        });
});