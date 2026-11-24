<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\CaptureController;
use App\Http\Controllers\DataController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\FoodServingController;
use App\Http\Controllers\FoodSearchController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MealController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\RecipeImportController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TargetsController;
use App\Http\Controllers\ShoppingController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TrainingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [TodayController::class, 'show'])->name('home');
    Route::get('/progress', [ProgressController::class, 'show'])->name('progress');
    Route::inertia('/more', 'More')->name('more');
    Route::inertia('/timer', 'Timer')->name('timer');
    Route::post('/weights', [ProgressController::class, 'weigh'])->middleware('throttle:60,1')->name('weights.store');
    Route::delete('/weights/{weight}', [ProgressController::class, 'destroyWeight'])->whereNumber('weight')->name('weights.destroy');
    Route::get('/review', [\App\Http\Controllers\ReviewController::class, 'show'])->name('review');
    Route::post('/review/generate', [\App\Http\Controllers\ReviewController::class, 'generate'])->middleware('throttle:10,1')->name('review.generate');
    Route::put('/event', [\App\Http\Controllers\ReviewController::class, 'saveEvent'])->name('event.save');
    Route::delete('/event', [\App\Http\Controllers\ReviewController::class, 'deleteEvent'])->name('event.delete');
    Route::post('/progress/checkin', [ProgressController::class, 'applyCheckin'])->name('progress.checkin');
    Route::get('/training', [TrainingController::class, 'show'])->name('training');
    Route::post('/training/template', [TrainingController::class, 'storeTemplate'])->name('training.template.store');
    Route::put('/training/template/{id}', [TrainingController::class, 'updateTemplate'])->whereNumber('id')->name('training.template.update');
    Route::delete('/training/template/{id}', [TrainingController::class, 'destroyTemplate'])->whereNumber('id')->name('training.template.destroy');
    Route::post('/workouts/parse', [TrainingController::class, 'parse'])->middleware('throttle:60,1')->name('workouts.parse');
    Route::post('/workouts', [TrainingController::class, 'store'])->middleware('throttle:60,1')->name('workouts.store');
    Route::post('/workouts/template-session', [TrainingController::class, 'mark'])->name('workouts.mark');
    Route::patch('/workouts/{workout}', [TrainingController::class, 'update'])->whereNumber('workout')->name('workouts.update');
    Route::delete('/workouts/{workout}', [TrainingController::class, 'destroy'])->whereNumber('workout')->name('workouts.destroy');
    Route::get('/plan', [PlanController::class, 'show'])->name('plan');
    Route::get('/targets', [TargetsController::class, 'show'])->name('targets');
    Route::put('/targets', [TargetsController::class, 'update'])->name('targets.update');

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
    Route::get('/meals/import', [RecipeImportController::class, 'show'])->name('meals.import');
    Route::post('/meals/import/preview', [RecipeImportController::class, 'preview'])->middleware('throttle:20,1')->name('meals.import.preview');
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
    Route::post('/shopping/{list}/todoist', [\App\Http\Controllers\TodoistController::class, 'export'])->whereNumber('list')->middleware('throttle:10,1')->name('shopping.todoist');
    Route::get('/settings/todoist', [\App\Http\Controllers\TodoistController::class, 'show'])->name('todoist');
    Route::post('/todoist/connect', [\App\Http\Controllers\TodoistController::class, 'connect'])->middleware('throttle:10,1')->name('todoist.connect');
    Route::delete('/todoist/connection', [\App\Http\Controllers\TodoistController::class, 'disconnect'])->name('todoist.disconnect');
    Route::post('/shopping/{list}/done', [ShoppingController::class, 'done'])->whereNumber('list')->name('shopping.done');
    Route::patch('/shopping/items/{item}', [ShoppingController::class, 'updateItem'])->whereNumber('item')->name('shopping.items.update');
    Route::delete('/shopping/items/{item}', [ShoppingController::class, 'destroyItem'])->whereNumber('item')->name('shopping.items.destroy');
    Route::post('/capture/parse', [CaptureController::class, 'parse'])->middleware('throttle:20,1')->name('capture.parse');
    Route::post('/capture/learn', [CaptureController::class, 'learn'])->middleware('throttle:60,1')->name('capture.learn');
    Route::get('/foods/barcode', [BarcodeController::class, 'show'])->middleware('throttle:60,1')->name('foods.barcode');
    Route::get('/foods/quick', [FoodSearchController::class, 'quick'])->name('foods.quick');
    Route::post('/foods/{food}/servings', [FoodServingController::class, 'store'])->whereNumber('food')->middleware('throttle:60,1')->name('foods.servings.store');
    Route::delete('/foods/servings/{serving}', [FoodServingController::class, 'destroy'])->whereNumber('serving')->name('foods.servings.destroy');
    Route::post('/foods/{food}/favourite', [FoodSearchController::class, 'favourite'])->name('foods.favourite');

    Route::post('/logs', [LogController::class, 'store'])->middleware('throttle:240,1')->name('logs.store');
    Route::post('/days/estimate', [\App\Http\Controllers\DayEstimateController::class, 'store'])->middleware('throttle:60,1')->name('days.estimate');
    Route::delete('/days/{date}/estimate', [\App\Http\Controllers\DayEstimateController::class, 'destroy'])->name('days.estimate.destroy');
    Route::post('/supplements', [\App\Http\Controllers\SupplementController::class, 'store'])->middleware('throttle:60,1')->name('supplements.store');
    Route::put('/supplements/creatine-plan', [\App\Http\Controllers\SupplementController::class, 'plan'])->middleware('throttle:30,1')->name('supplements.plan');
    Route::delete('/supplements/creatine-plan', [\App\Http\Controllers\SupplementController::class, 'clearPlan'])->name('supplements.plan.clear');
    Route::delete('/supplements/{id}', [\App\Http\Controllers\SupplementController::class, 'destroy'])->whereNumber('id')->name('supplements.destroy');
    Route::post('/water', [\App\Http\Controllers\WaterController::class, 'store'])->middleware('throttle:120,1')->name('water.store');
    Route::delete('/water/{id}', [\App\Http\Controllers\WaterController::class, 'destroy'])->whereNumber('id')->name('water.destroy');
    Route::post('/readiness', [\App\Http\Controllers\ReadinessController::class, 'store'])->middleware('throttle:60,1')->name('readiness.store');
    Route::delete('/readiness/{date}', [\App\Http\Controllers\ReadinessController::class, 'destroy'])->name('readiness.destroy');
    Route::get('/principles', [\App\Http\Controllers\PrincipleController::class, 'show'])->name('principles');
    Route::post('/principles/import', [\App\Http\Controllers\PrincipleController::class, 'import'])->middleware('throttle:20,1')->name('principles.import');
    Route::put('/principles/stance', [\App\Http\Controllers\PrincipleController::class, 'saveStance'])->name('principles.my-stance');
    Route::patch('/principles/{principle}/stance', [\App\Http\Controllers\PrincipleController::class, 'stance'])->whereNumber('principle')->name('principles.stance');
    Route::delete('/principles/{principle}', [\App\Http\Controllers\PrincipleController::class, 'archive'])->whereNumber('principle')->name('principles.archive');
    Route::get('/lifting', [\App\Http\Controllers\HevyController::class, 'show'])->name('lifting');
    Route::get('/lifting/routines', [\App\Http\Controllers\RoutinesController::class, 'index'])->name('lifting.routines');
    Route::get('/lifting/routines/{routine}', [\App\Http\Controllers\RoutinesController::class, 'show'])->whereNumber('routine')->name('lifting.routine');
    Route::get('/lifting/progression', [\App\Http\Controllers\RoutinesController::class, 'progression'])->name('lifting.progression');
    Route::get('/lifting/sessions', [\App\Http\Controllers\LiftSessionsController::class, 'index'])->name('lifting.sessions');
    Route::get('/lifting/best', [\App\Http\Controllers\LiftsController::class, 'board'])->name('lifting.best');
    Route::get('/lifting/exercise/{id}', [\App\Http\Controllers\LiftsController::class, 'show'])->where('id', '[A-Za-z0-9_\-]+')->name('lifting.exercise');
    Route::post('/lifting/exercise/{id}/notes', [\App\Http\Controllers\LiftsController::class, 'storeNote'])->where('id', '[A-Za-z0-9_\-]+')->middleware('throttle:60,1')->name('lifting.notes.store');
    Route::put('/lifting/exercise/{id}/step', [\App\Http\Controllers\LiftsController::class, 'saveStep'])->where('id', '[A-Za-z0-9_\\-]+')->name('lifting.step');
    Route::delete('/lifting/exercise/{id}/step', [\App\Http\Controllers\LiftsController::class, 'clearStep'])->where('id', '[A-Za-z0-9_\\-]+')->name('lifting.step.clear');
    Route::patch('/lifting/notes/{note}', [\App\Http\Controllers\LiftsController::class, 'updateNote'])->whereNumber('note')->name('lifting.notes.update');
    Route::delete('/lifting/notes/{note}', [\App\Http\Controllers\LiftsController::class, 'destroyNote'])->whereNumber('note')->name('lifting.notes.destroy');
    Route::get('/settings/notifications', [\App\Http\Controllers\PushController::class, 'show'])->name('notifications');
    Route::post('/push/subscribe', [\App\Http\Controllers\PushController::class, 'subscribe'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::post('/push/unsubscribe', [\App\Http\Controllers\PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::put('/push/prefs', [\App\Http\Controllers\PushController::class, 'prefs'])->name('push.prefs');
    Route::post('/push/test', [\App\Http\Controllers\PushController::class, 'test'])->middleware('throttle:5,1')->name('push.test');
    Route::get('/watch', [\App\Http\Controllers\WatchController::class, 'show'])->name('watch');
    Route::get('/recovery', [\App\Http\Controllers\RecoveryController::class, 'show'])->name('recovery');
    Route::put('/recovery/sleep-target', [\App\Http\Controllers\RecoveryController::class, 'sleepTarget'])->name('recovery.sleep');
    Route::post('/recovery/deload', [\App\Http\Controllers\RecoveryController::class, 'deload'])->name('recovery.deload');
    Route::post('/watch/connect', [\App\Http\Controllers\WatchController::class, 'connect'])->middleware('throttle:10,1')->name('watch.connect');
    Route::post('/watch/sync', [\App\Http\Controllers\WatchController::class, 'sync'])->middleware('throttle:10,1')->name('watch.sync');
    Route::post('/watch/resync', [\App\Http\Controllers\WatchController::class, 'resync'])->middleware('throttle:10,1')->name('watch.resync');
    Route::delete('/watch/connection', [\App\Http\Controllers\WatchController::class, 'disconnect'])->name('watch.disconnect');
    Route::post('/lifting/connect', [\App\Http\Controllers\HevyController::class, 'connect'])->middleware('throttle:10,1')->name('lifting.connect');
    Route::post('/lifting/sync', [\App\Http\Controllers\HevyController::class, 'sync'])->middleware('throttle:10,1')->name('lifting.sync');
    Route::put('/lifting/effort-ramp', [\App\Http\Controllers\HevyController::class, 'saveRamp'])->name('lifting.ramp');
    Route::delete('/lifting/effort-ramp', [\App\Http\Controllers\HevyController::class, 'clearRamp'])->name('lifting.ramp.clear');
    Route::delete('/lifting/connection', [\App\Http\Controllers\HevyController::class, 'disconnect'])->name('lifting.disconnect');
    Route::get('/coach', [\App\Http\Controllers\CoachController::class, 'show'])->name('coach');
    Route::post('/coach/messages', [\App\Http\Controllers\CoachController::class, 'ask'])->middleware('throttle:20,1')->name('coach.ask');
    Route::delete('/coach/threads/{thread}', [\App\Http\Controllers\CoachController::class, 'destroyThread'])->whereNumber('thread')->name('coach.destroy');
    Route::post('/logs/copy', [LogController::class, 'copy'])->name('logs.copy');
    Route::patch('/logs/{log}', [LogController::class, 'update'])->name('logs.update');
    Route::delete('/logs/{log}', [LogController::class, 'destroy'])->name('logs.destroy');

    Route::get('/foods/search', [FoodSearchController::class, 'index'])->middleware('throttle:120,1')->name('foods.search');

    Route::get('/settings/data', [DataController::class, 'show'])->name('settings.data');
    Route::get('/settings/data/backup.json', [DataController::class, 'backup'])->name('settings.data.backup');
    Route::get('/settings/data/{what}.csv', [DataController::class, 'csv'])->whereIn('what', ['food-logs', 'weights', 'workouts'])->name('settings.data.csv');
    Route::post('/import/rows', [DataController::class, 'rows'])->middleware('throttle:60,1')->name('import.rows');
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
