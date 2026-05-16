<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\ReviewController;

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

Route::get('/categories',            [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::get('/brands',         [BrandController::class, 'index']);
Route::get('/brands/{brand}', [BrandController::class, 'show']);
Route::get('/products',                    [ProductController::class, 'index']);
Route::get('/products/{product}',          [ProductController::class, 'show']);
Route::get('/products/{product}/variants', [ProductVariantController::class, 'index']);

// Protected
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout',         [AuthController::class, 'logout']);
    Route::get('/me',              [AuthController::class, 'me']);
    Route::put('/profile',         [AuthController::class, 'updateProfile']);
    Route::put('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/user', fn(Request $request) => $request->user());

    Route::get('/orders',                  [OrderController::class, 'index']);
    Route::post('/orders',                 [OrderController::class, 'store']);
    Route::get('/orders/{order}',          [OrderController::class, 'show']);
    Route::patch('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    Route::get('/addresses',                     [AddressController::class, 'index']);
    Route::post('/addresses',                    [AddressController::class, 'store']);
    Route::put('/addresses/{address}',           [AddressController::class, 'update']);
    Route::delete('/addresses/{address}',        [AddressController::class, 'destroy']);
    Route::patch('/addresses/{address}/default', [AddressController::class, 'setDefault']);

    Route::post('/payment/initiate/{order}', [PaymentController::class, 'initiate']);
    Route::get('/payment/status/{order}',    [PaymentController::class, 'status']);
    Route::delete('/payment/cancel/{order}', [PaymentController::class, 'cancel']);

        // inside Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wishlist',        [WishlistController::class, 'index']);
    Route::post('/wishlist/toggle',[WishlistController::class, 'toggle']);
    Route::get('/wishlist/ids',    [WishlistController::class, 'ids']);

    // Public
    Route::get('/products/{product}/reviews', [ReviewController::class, 'index']);

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/reviews', [ReviewController::class, 'store']);
    });

    // Admin only
    Route::middleware('admin')->group(function () {

        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{category}',    [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/brands',           [BrandController::class, 'store']);
        Route::put('/brands/{brand}',    [BrandController::class, 'update']);
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);

        Route::post('/products',             [ProductController::class, 'store']);
        Route::put('/products/{product}',    [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::post('/products/{product}/variants',             [ProductVariantController::class, 'store']);
        Route::put('/products/{product}/variants/{variant}',    [ProductVariantController::class, 'update']);
        Route::delete('/products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy']);

        // ✅ FIXED: moved to /admin/orders — was /orders/admin/all which conflicted with /orders/{order}
        Route::get('/admin/orders',            [OrderController::class, 'adminIndex']);
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::delete('/orders/{order}',       [OrderController::class, 'destroy']);

        Route::get('/admin/low-stock', [ProductVariantController::class, 'lowStock']);
    });
});