<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\FoodSearchController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MealController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ShoppingController;
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
    Route::get('/meals', [MealController::class, 'index'])->name('meals.index');
    Route::get('/meals/list', [MealController::class, 'list'])->name('meals.list');
    Route::post('/meals', [MealController::class, 'store'])->name('meals.store');
    Route::put('/meals/{meal}', [MealController::class, 'update'])->whereNumber('meal')->name('meals.update');
    Route::delete('/meals/{meal}', [MealController::class, 'destroy'])->whereNumber('meal')->name('meals.destroy');
    Route::post('/meals/{meal}/batches', [MealController::class, 'storeBatch'])->whereNumber('meal')->name('meals.batches.store');
    Route::delete('/meals/batches/{batch}', [MealController::class, 'destroyBatch'])->whereNumber('batch')->name('meals.batches.destroy');
    Route::get('/shopping', [ShoppingController::class, 'index'])->name('shopping.index');
    Route::post('/shopping', [ShoppingController::class, 'store'])->name('shopping.store');
    Route::get('/shopping/{list}', [ShoppingController::class, 'show'])->whereNumber('list')->name('shopping.show');
    Route::put('/shopping/{list}/recipes', [ShoppingController::class, 'recipes'])->whereNumber('list')->name('shopping.recipes');
    Route::post('/shopping/{list}/refresh', [ShoppingController::class, 'refresh'])->whereNumber('list')->name('shopping.refresh');
    Route::post('/shopping/{list}/items', [ShoppingController::class, 'addItem'])->whereNumber('list')->name('shopping.items.store');
    Route::post('/shopping/{list}/sync', [ShoppingController::class, 'sync'])->whereNumber('list')->middleware('throttle:120,1')->name('shopping.sync');
    Route::post('/shopping/{list}/done', [ShoppingController::class, 'done'])->whereNumber('list')->name('shopping.done');
    Route::patch('/shopping/items/{item}', [ShoppingController::class, 'updateItem'])->whereNumber('item')->name('shopping.items.update');
    Route::delete('/shopping/items/{item}', [ShoppingController::class, 'destroyItem'])->whereNumber('item')->name('shopping.items.destroy');
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
