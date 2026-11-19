<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\FoodSearchController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [TodayController::class, 'show'])->name('home');
    Route::get('/plan', [PlanController::class, 'show'])->name('plan');

    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::get('/profile', [OnboardingController::class, 'show'])->name('profile.edit');
    Route::put('/profile', [OnboardingController::class, 'store'])->name('profile.update');

    Route::get('/foods', [FoodController::class, 'index'])->name('foods.index');
    Route::post('/foods', [FoodController::class, 'store'])->name('foods.store');
    Route::put('/foods/{food}', [FoodController::class, 'update'])->name('foods.update');
    Route::delete('/foods/{food}', [FoodController::class, 'destroy'])->name('foods.destroy');
    Route::get('/foods/barcode', [BarcodeController::class, 'show'])->middleware('throttle:60,1')->name('foods.barcode');
    Route::get('/foods/quick', [FoodSearchController::class, 'quick'])->name('foods.quick');
    Route::post('/foods/{food}/favourite', [FoodSearchController::class, 'favourite'])->name('foods.favourite');

    Route::post('/logs', [LogController::class, 'store'])->middleware('throttle:240,1')->name('logs.store');
    Route::post('/logs/copy', [LogController::class, 'copy'])->name('logs.copy');
    Route::patch('/logs/{log}', [LogController::class, 'update'])->name('logs.update');
    Route::delete('/logs/{log}', [LogController::class, 'destroy'])->name('logs.destroy');

    Route::get('/foods/search', [FoodSearchController::class, 'index'])->middleware('throttle:120,1')->name('foods.search');

    Route::get('/settings/about', [AboutController::class, 'show'])->name('settings.about');

    // Passkey management needs a recent password confirmation (also enforced on the JSON endpoints by Fortify).
    Route::get('/settings/security', [SecurityController::class, 'show'])
        ->middleware('password.confirm')
        ->name('settings.security');
});

// The service worker must be served from the site root to control "/" (it is built into public/build).
Route::get('/sw.js', function () {
    $file = public_path('build/sw.js');
    abort_unless(is_file($file), 404);

    return response()->file($file, [
        'Content-Type' => 'text/javascript',
        'Service-Worker-Allowed' => '/',
        'Cache-Control' => 'no-cache',
    ]);
});
