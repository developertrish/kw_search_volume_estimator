<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KeywordVolumeController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('seo/keyword-volume')->group(function () {
    Route::post('single', [KeywordVolumeController::class, 'single']);
    Route::post('batch', [KeywordVolumeController::class, 'batch']);
    Route::get('tiers', [KeywordVolumeController::class, 'tiers']);
});
