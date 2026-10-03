<?php

use App\Http\Controllers\Api\AdminItemController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\ChoreCompletionController;
use App\Http\Controllers\Api\ChoreController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FoodEntryController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\ItemPriceController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RecurringBillController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ShoppingItemController;
use App\Http\Controllers\Api\ShoppingTripController;
use App\Http\Controllers\Api\SleepEntryController;
use App\Http\Controllers\Api\SupplyController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\WaterEntryController;
use App\Http\Controllers\Api\WeightController;
use App\Http\Controllers\Api\WorkoutController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::get('households/{household}/members', [HouseholdController::class, 'members'])->name('households.members');
        Route::post('households/{household}/invites', [InviteController::class, 'store'])->name('invites.store');
        Route::post('invites/{invite}/accept', [InviteController::class, 'accept'])->middleware('signed')->name('invites.accept');

        // Each module's rows, always inside one household
        Route::prefix('households/{household}')->middleware('can:view,household')->group(function () {
            Route::apiResources([
                'expenses' => ExpenseController::class,
                'recurring-bills' => RecurringBillController::class,
                'budgets' => BudgetController::class,
                'inventory-items' => InventoryItemController::class,
                'purchases' => PurchaseController::class,
                'shopping-items' => ShoppingItemController::class,
                'shopping-trips' => ShoppingTripController::class,
                'item-prices' => ItemPriceController::class,
                'rooms' => RoomController::class,
                'chores' => ChoreController::class,
                'chore-completions' => ChoreCompletionController::class,
                'supplies' => SupplyController::class,
                'food-entries' => FoodEntryController::class,
                'water-entries' => WaterEntryController::class,
                'sleep-entries' => SleepEntryController::class,
                'workouts' => WorkoutController::class,
                'weights' => WeightController::class,
                'events' => EventController::class,
                'todos' => TodoController::class,
                'admin-items' => AdminItemController::class,
            ]);
        });
    });
});
