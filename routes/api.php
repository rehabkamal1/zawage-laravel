<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\MatchingController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\PaymentController;

// Public Guest Auth Routes
Route::post('/send-otp', [AuthController::class, 'sendOTP']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Paymob Callback Webhook & Sandbox simulation routes (Publicly Accessible)
Route::post('/payments/callback', [PaymentController::class, 'handleCallback']);
Route::get('/subscriptions/{id}/simulate-payment-page', [PaymentController::class, 'simulatePaymentPage']);
Route::post('/subscriptions/{id}/simulate-payment', [PaymentController::class, 'simulatePayment']);

// Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/password', [AuthController::class, 'updatePassword']);

    // Profile routes
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // Matching & Contact routes
    Route::get('/matches', [MatchingController::class, 'index']);
    Route::post('/matches/{id}/contact', [MatchingController::class, 'contact']);

    // Subscriptions/Payment initiation routes
    Route::post('/subscriptions/subscribe', [PaymentController::class, 'subscribe']);

    // Favorite routes
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites', [FavoriteController::class, 'toggle']);

    // Reporting (User side)
    Route::post('/reports', [ReportController::class, 'store']);

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'indexUsers']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::post('/users/{id}/toggle-ban', [AdminController::class, 'toggleBan']);
        
        Route::get('/reports', [ReportController::class, 'index']);
        Route::put('/reports/{id}', [ReportController::class, 'update']);
    });
});
