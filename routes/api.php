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

// PASSWORD RESET
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// EMAIL VERIFICATION
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerificationEmail']);

    Route::get('/me', [AuthController::class, 'me']);

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/debug-auth', function () {
        return [
            'id' => auth()->id(),
            'email' => auth()->user()?->email,
            'email_verified_at' => auth()->user()?->email_verified_at,
        ];
    });
});

/*
|--------------------------------------------------------------------------
| PUBLIC PRODUCTS & CATEGORIES
|--------------------------------------------------------------------------
*/

// PUBLIC CATEGORIES
Route::get('/categories', [CategoryController::class, 'index']);

// PUBLIC PRODUCTS
Route::get('/products', [ProductController::class, 'index']);

// SELLER PRODUCTS
// IMPORTANT: DAPAT MAUNA NI SA /products/{id}
Route::get('/products/my', [ProductController::class, 'myProducts'])
    ->middleware('auth:sanctum');

// PUBLIC PRODUCT DETAILS
Route::get('/products/{id}', [ProductController::class, 'show']);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (auth + verified)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'verified'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTH USER
    |--------------------------------------------------------------------------
    */

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

    // CREATE PRODUCT
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('role:seller,system_admin,admin');

    // UPDATE PRODUCT
    Route::put('/products/{id}', [ProductController::class, 'update']);

    // DELETE PRODUCT
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | CATEGORIES (Admin)
    |--------------------------------------------------------------------------
    */

    // CREATE CATEGORY
    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware('role:system_admin,admin');

    // UPDATE CATEGORY
    Route::put('/categories/{id}', [CategoryController::class, 'update'])
        ->middleware('role:system_admin,admin');

    // DELETE CATEGORY
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])
        ->middleware('role:system_admin,admin');

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

    // DELETE ORDER (Admin only)
    Route::delete('/orders/{id}', [OrderController::class, 'destroy'])
        ->middleware('role:system_admin,admin');

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);

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

            Route::put('/users/{id}/approve-seller', [UserController::class, 'approveSeller']);

            Route::put('/users/{id}/reject-seller', [UserController::class, 'rejectSeller']);

            Route::get('/products', [ProductController::class, 'adminProducts']);

            Route::put('/products/{id}/approve', [ProductController::class, 'approveProduct']);

            Route::put('/products/{id}/reject', [ProductController::class, 'rejectProduct']);

            Route::get('/orders/stats/all', [OrderController::class, 'allStats']);
        });

});