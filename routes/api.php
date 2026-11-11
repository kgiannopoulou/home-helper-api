<?php

use App\Enums\PlantCareType;
use App\Http\Controllers\Api\AdminItemController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BriefingController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\ChoreCompletionController;
use App\Http\Controllers\Api\ChoreController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FoodEntryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\ItemPriceController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\PlantAlertController;
use App\Http\Controllers\Api\PlantCareController;
use App\Http\Controllers\Api\PlantCareLogController;
use App\Http\Controllers\Api\PlantController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RecurringBillController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ShoppingItemController;
use App\Http\Controllers\Api\ShoppingTripController;
use App\Http\Controllers\Api\SleepEntryController;
use App\Http\Controllers\Api\SupplyController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\VoiceController;
use App\Http\Controllers\Api\WaterEntryController;
use App\Http\Controllers\Api\WeightController;
use App\Http\Controllers\Api\WorkoutController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('login');

    // For an uptime monitor; details only with an admin's token (docs/operations.md)
    Route::get('health', HealthController::class)->middleware('throttle:health')->name('health');

    // Limits: app/Providers/AppServiceProvider.php::configureRateLimits()
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        // Voice settings and speech (docs/voice.md#api)
        Route::get('voice', [VoiceController::class, 'show'])->name('voice.show');
        Route::patch('voice', [VoiceController::class, 'update'])->name('voice.update');
        Route::post('voice/speak', [VoiceController::class, 'speak'])->middleware('throttle:voice')->name('voice.speak');
        Route::delete('voice/history', [VoiceController::class, 'forget'])->name('voice.forget');
        Route::delete('devices', [DeviceController::class, 'destroy'])->name('devices.destroy');

        Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::patch('households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::get('households/{household}/members', [HouseholdController::class, 'members'])->name('households.members');
        Route::post('households/{household}/invites', [InviteController::class, 'store'])->middleware('throttle:invites')->name('invites.store');
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
                'plants' => PlantController::class,
                'plant-care-logs' => PlantCareLogController::class,
            ]);

            // The assistant (docs/assistant.md): your own conversations in this household
            Route::prefix('assistant')->name('assistant.')->controller(AssistantController::class)->group(function () {
                Route::get('tools', 'tools')->name('tools');
                Route::get('conversations', 'index')->name('conversations.index');
                Route::post('conversations', 'store')->middleware('throttle:ai')->name('conversations.store');
                Route::get('conversations/{conversation}', 'show')->name('conversations.show');
                Route::delete('conversations/{conversation}', 'destroy')->name('conversations.destroy');
                Route::post('conversations/{conversation}/messages', 'message')->middleware('throttle:ai')->name('conversations.message');
                Route::post('actions/{action}/confirm', 'confirm')->name('actions.confirm');
                Route::post('actions/{action}/decline', 'decline')->name('actions.decline');
            });

            // Plant care: one action per kind of care, POST …/plants/{plant}/water {amount_ml, performed_at}
            foreach (PlantCareType::cases() as $care) {
                Route::post("plants/{plant}/{$care->verb()}", [PlantCareLogController::class, 'record'])
                    ->defaults('type', $care->value)
                    ->name("plants.{$care->verb()}");
            }
            Route::get('plants/{plant}/care-history', [PlantCareLogController::class, 'history'])->name('plants.care-history');
            Route::get('plant-alerts', [PlantAlertController::class, 'index'])->name('plant-alerts.index');
            Route::post('plant-alerts/{plantAlert}/resolve', [PlantAlertController::class, 'resolve'])->name('plant-alerts.resolve');
            Route::prefix('plant-care')->name('plant-care.')->controller(PlantCareController::class)->group(function () {
                Route::get('today', 'today')->name('today');
                Route::get('plan', 'plan')->name('plan');
            });

            // Memory (docs/memory.md#api): facts, notes and documents, search
            Route::prefix('memory')->name('memory.')->controller(MemoryController::class)->group(function () {
                Route::get('facts', 'facts')->name('facts.index');
                Route::post('facts', 'storeFact')->name('facts.store');
                Route::patch('facts/{fact}', 'updateFact')->name('facts.update');
                Route::delete('facts/{fact}', 'destroyFact')->name('facts.destroy');
                Route::get('notes', 'notes')->name('notes.index');
                Route::post('notes', 'storeNote')->middleware('throttle:uploads')->name('notes.store');
                Route::get('notes/{note}', 'showNote')->name('notes.show');
                Route::patch('notes/{note}', 'updateNote')->name('notes.update');
                Route::delete('notes/{note}', 'destroyNote')->name('notes.destroy');
                Route::get('notes/{note}/file', 'download')->name('notes.file');
                Route::get('search', 'search')->name('search');
            });

            // Household intelligence (docs/briefings.md#api)
            Route::controller(BriefingController::class)->group(function () {
                Route::get('briefing', 'briefing')->name('briefing');
                Route::get('recommendations', 'recommendations')->name('recommendations.index');
                Route::post('recommendations/feedback', 'feedback')->name('recommendations.feedback');
                Route::get('timeline', 'timeline')->name('timeline');
                Route::get('summaries', 'summaries')->name('summaries.index');
            });

            // Talking to the assistant (docs/voice.md#api)
            Route::post('voice/turns', [VoiceController::class, 'turn'])->middleware('throttle:ai')->name('voice.turns.store');
            Route::post('voice/turns/{turn}/cancel', [VoiceController::class, 'cancel'])->name('voice.turns.cancel');

            Route::post('sync', SyncController::class)->middleware('throttle:sync')->name('sync');

            Route::prefix('insights')->name('insights.')->controller(InsightController::class)->group(function () {
                Route::get('weekly-spending', 'weeklySpending')->name('weekly-spending');
                Route::get('run-out', 'runOut')->name('run-out');
                Route::get('slipping-chores', 'slippingChores')->name('slipping-chores');
                Route::get('shopping-day', 'shoppingDay')->name('shopping-day');
                Route::get('budget-forecast', 'budgetForecast')->name('budget-forecast');
            });
        });
    });
});
