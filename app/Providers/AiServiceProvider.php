<?php

namespace App\Providers;

use App\Ai\ChatModel;
use App\Ai\Providers\ClaudeChatModel;
use App\Ai\Providers\OfflineChatModel;
use App\Ai\Tools\Finance\GetSpendingSummary;
use App\Ai\Tools\Finance\GetUpcomingBills;
use App\Ai\Tools\Finance\ListExpenses;
use App\Ai\Tools\Household\GetHouseholdOverview;
use App\Ai\Tools\Household\GetTodayPlan;
use App\Ai\Tools\Plants\GetPlantCareToday;
use App\Ai\Tools\Plants\GetWeatherForecast;
use App\Ai\Tools\Plants\ListHomeMaintenance;
use App\Ai\Tools\Plants\ListPlantAlerts;
use App\Ai\Tools\Plants\LogPlantCare;
use App\Ai\Tools\Shopping\AddShoppingItem;
use App\Ai\Tools\Shopping\CheckOffShoppingItem;
use App\Ai\Tools\Shopping\GetKitchenInventory;
use App\Ai\Tools\Shopping\GetShoppingList;
use App\Ai\Tools\Tasks\AddTodo;
use App\Ai\Tools\Tasks\CompleteTodo;
use App\Ai\Tools\Tasks\ListOverdueChores;
use App\Ai\Tools\Tasks\ListTodos;
use App\Ai\Tools\Tasks\ListUpcomingEvents;
use App\Ai\Tools\Tasks\MarkChoreDone;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * The assistant's wiring: which model (homehelper.ai.provider) and which tools.
 * A new tool is one class here plus its test (docs/assistant.md#adding-a-tool).
 */
class AiServiceProvider extends ServiceProvider
{
    /** @var list<class-string<Tool>> */
    public const TOOLS = [
        // Household (day 23)
        GetHouseholdOverview::class,
        GetTodayPlan::class,
        // Tasks, shopping and the kitchen (day 24)
        ListTodos::class,
        ListOverdueChores::class,
        ListUpcomingEvents::class,
        GetShoppingList::class,
        GetKitchenInventory::class,
        AddShoppingItem::class,
        CheckOffShoppingItem::class,
        AddTodo::class,
        CompleteTodo::class,
        MarkChoreDone::class,
        // Money, read only (day 25)
        GetSpendingSummary::class,
        ListExpenses::class,
        GetUpcomingBills::class,
        // Plants, weather and upkeep (day 26)
        GetPlantCareToday::class,
        ListPlantAlerts::class,
        GetWeatherForecast::class,
        ListHomeMaintenance::class,
        LogPlantCare::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, fn (Application $app) => new ToolRegistry(
            array_map(fn (string $class) => $app->make($class), self::TOOLS),
        ));

        $this->app->bind(ChatModel::class, fn () => match (config('homehelper.ai.provider')) {
            'claude' => new ClaudeChatModel(
                apiKey: config('services.anthropic.api_key'),
                model: (string) config('homehelper.ai.model'),
                effort: (string) config('homehelper.ai.effort'),
                maxTokens: (int) config('homehelper.ai.max_tokens'),
                timeout: (int) config('services.anthropic.timeout'),
            ),
            'offline' => new OfflineChatModel,
            default => throw new InvalidArgumentException('AI_PROVIDER must be claude or offline.'),
        });
    }
}
