<?php

use App\Http\Controllers\Web\AssistantController;
use App\Http\Controllers\Web\BriefingController;
use App\Http\Controllers\Web\ChoresController;
use App\Http\Controllers\Web\ComputersController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HealthController;
use App\Http\Controllers\Web\HouseholdController;
use App\Http\Controllers\Web\MemoryController;
use App\Http\Controllers\Web\PlannerController;
use App\Http\Controllers\Web\SmartHomeController;
use App\Http\Controllers\Web\SpendingController;
use App\Http\Controllers\Web\SystemHealthController;
use App\Http\Controllers\Web\VoiceController;
use App\Http\Middleware\EnsureHousehold;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// A spoken answer: a signed URL that lasts a few minutes (App\Voice\VoiceTurns), so the phone can play it too
Route::get('voice/audio/{id}', [VoiceController::class, 'audio'])->middleware('signed')->name('voice.audio');

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

        // Today: the briefing, recommendations, timeline, summaries (docs/briefings.md)
        Route::get('briefing', [BriefingController::class, 'show'])->name('briefing');
        Route::post('briefing/feedback', [BriefingController::class, 'feedback'])->name('briefing.feedback');

        // What Home Helper remembers (docs/memory.md)
        Route::controller(MemoryController::class)->prefix('memory')->group(function () {
            Route::get('/', 'show')->name('memory');
            Route::post('facts', 'storeFact')->name('memory.facts.store');
            Route::delete('facts/{fact}', 'destroyFact')->name('memory.facts.destroy');
            Route::post('notes', 'storeNote')->middleware('throttle:uploads')->name('memory.notes.store');
            Route::delete('notes/{note}', 'destroyNote')->name('memory.notes.destroy');
        });

        // Talking to the assistant (docs/voice.md): JSON for the page's recorder and player
        Route::controller(VoiceController::class)->prefix('voice')->name('voice.')->group(function () {
            Route::get('config', 'config')->name('config');
            Route::post('turns', 'turn')->middleware('throttle:ai')->name('turns.store');
            Route::post('turns/{turn}/cancel', 'cancel')->name('turns.cancel');
            Route::post('speak', 'speak')->middleware('throttle:voice')->name('speak');
        });

        // The household's computers, through the local agent (agent/docs/design.md)
        Route::controller(ComputersController::class)->prefix('computers')->name('computers')->group(function () {
            Route::get('/', 'show');
            Route::post('pair', 'pair')->middleware('throttle:10,1')->name('.pair');
            Route::post('{agent}/commands', 'run')->middleware('throttle:30,1')->name('.commands.store');
            Route::post('commands/{command}/confirm', 'confirm')->name('.commands.confirm');
            Route::post('commands/{command}/cancel', 'cancel')->name('.commands.cancel');
            Route::post('{agent}/update', 'update')->name('.update');
            Route::patch('{agent}', 'rename')->name('.rename');
            Route::delete('{agent}', 'revoke')->name('.revoke');
        });

        // Lights, plugs and sensors through the household's Home Assistant (docs/smart-home.md)
        Route::controller(SmartHomeController::class)->prefix('smart-home')->name('smart-home')->group(function () {
            Route::get('/', 'show');
            Route::post('connection', 'connect')->middleware('throttle:10,1')->name('.connection.store');
            Route::post('connection/check', 'check')->middleware('throttle:20,1')->name('.connection.check');
            Route::post('connection/secret', 'rotate')->middleware('throttle:10,1')->name('.connection.secret');
            Route::delete('connection', 'disconnect')->name('.connection.destroy');
            Route::post('sync', 'sync')->middleware('throttle:10,1')->name('.sync');
            Route::patch('notifications', 'notifications')->name('.notifications.update');
            Route::post('devices/import', 'import')->name('.devices.import');
            Route::patch('devices/{device}', 'updateDevice')->name('.devices.update');
            Route::delete('devices/{device}', 'removeDevice')->name('.devices.destroy');
            Route::post('devices/{device}/actions', 'control')->middleware('throttle:smart-home-control')->name('.devices.actions.store');
        });

        // The assistant (docs/assistant.md)
        Route::controller(AssistantController::class)->prefix('assistant')->group(function () {
            Route::get('/', 'show')->name('assistant');
            Route::post('/', 'start')->middleware('throttle:ai')->name('assistant.start');
            Route::post('actions/{action}/confirm', 'confirm')->name('assistant.actions.confirm');
            Route::post('actions/{action}/decline', 'decline')->name('assistant.actions.decline');
            Route::get('{conversation}', 'show')->name('assistant.show');
            Route::post('{conversation}/messages', 'message')->middleware('throttle:ai')->name('assistant.message');
            Route::delete('{conversation}', 'destroy')->name('assistant.destroy');
        });
    });

    // Admins (OPS_ADMIN_EMAILS) only; works without a household
    Route::get('system/health', SystemHealthController::class)->middleware('can:viewSystemHealth')->name('system.health');
});

require __DIR__.'/settings.php';
