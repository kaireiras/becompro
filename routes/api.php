<?php

use App\Http\Controllers\API\PatientController;
use App\Http\Controllers\API\DashboardController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\JenishewanController;
use App\Http\Controllers\HewanController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\SystemInfoController;
use App\Http\Controllers\AdminController;

Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/media', [MediaController::class, 'index']);
Route::get('/media/statistics', [MediaController::class, 'statistics']);

require __DIR__.'/auth.php';

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/media', [MediaController::class, 'store']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy']);

    Route::apiResource('articles', ArticleController::class)->except('index');

    Route::apiResource('jenis-hewan', JenishewanController::class);
    Route::apiResource('hewan', HewanController::class);
    Route::apiResource('patients',PatientController::class);

    Route::apiResource('reservations', ReservationController::class);
    Route::patch('reservations/{reservation}/status', [ReservationController::class, 'updateStatus']);

    Route::apiResource('promos', PromoController::class);

    Route::get('/system-info', [SystemInfoController::class, 'index']);
    Route::put('/system-info', [SystemInfoController::class, 'update']);
    
    Route::get('/social-media', [SystemInfoController::class, 'getSocialMedia']);
    Route::post('/social-media', [SystemInfoController::class, 'storeSocialMedia']);
    Route::put('/social-media/{social_media}', [SystemInfoController::class, 'updateSocialMedia']);
    Route::delete('/social-media/{social_media}', [SystemInfoController::class, 'deleteSocialMedia']);

    // Amanajemen admin
    Route::apiResource('admins', AdminController::class);

    Route::get('/dashboard/statistics', [DashboardController::class, 'getStatistics']);
    Route::get('/dashboard/clinic-summary', [DashboardController::class, 'getClinicSummary']);
    Route::get('/dashboard/recent-transactions', [DashboardController::class, 'getRecentTransactions']);
});


Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});
