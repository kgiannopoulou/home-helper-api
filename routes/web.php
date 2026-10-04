<?php

use App\Http\Controllers\Web\ChoresController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HealthController;
use App\Http\Controllers\Web\HouseholdController;
use App\Http\Controllers\Web\PlannerController;
use App\Http\Controllers\Web\SpendingController;
use App\Http\Middleware\EnsureHousehold;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Works without a household: that's where you start or join one
    Route::get('household', [HouseholdController::class, 'show'])->name('household.show');
    Route::post('household', [HouseholdController::class, 'store'])->name('household.store');
    Route::post('household/invites', [HouseholdController::class, 'invite'])->middleware('throttle:10,1')->name('household.invite');
    Route::post('household/switch/{household}', [HouseholdController::class, 'switch'])->name('household.switch');

    Route::middleware(EnsureHousehold::class)->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('spending', SpendingController::class)->name('spending');
        Route::get('health', HealthController::class)->name('health');
        Route::get('chores', ChoresController::class)->name('chores');
        Route::get('planner', [PlannerController::class, 'show'])->name('planner');
        Route::post('planner/apply', [PlannerController::class, 'apply'])->name('planner.apply');
    });
});

require __DIR__.'/settings.php';
