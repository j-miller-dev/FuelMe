<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\FoodSearchController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [PlanController::class, 'show'])->name('home');

    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::get('/profile', [OnboardingController::class, 'show'])->name('profile.edit');
    Route::put('/profile', [OnboardingController::class, 'store'])->name('profile.update');

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
