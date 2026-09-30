<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContributionController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PayoutController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\PaystackWebhookController;
use App\Http\Middleware\EnsureApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');
    Route::post('paystack/webhook', PaystackWebhookController::class);

    Route::middleware(['auth:sanctum', EnsureApiToken::class])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('groups', [GroupController::class, 'index']);
        Route::post('groups', [GroupController::class, 'store']);
        Route::post('groups/join', [GroupController::class, 'join']);
        Route::post('groups/lookup', [GroupController::class, 'lookup']);
        Route::get('groups/{group}', [GroupController::class, 'show']);
        Route::get('groups/{group}/schedule', [GroupController::class, 'schedule']);
        Route::get('groups/{group}/contributions', [GroupController::class, 'contributions']);
        Route::post('groups/{group}/contributions/{contribution}/checkout', [ContributionController::class, 'checkout']);
        Route::post('groups/{group}/contributions/{contribution}/verify', [ContributionController::class, 'verify']);
        Route::post('groups/{group}/complete-cycle', [PayoutController::class, 'completeCycle']);
        Route::post('groups/{group}/settle-payout', [PayoutController::class, 'settle']);

        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    });
});
