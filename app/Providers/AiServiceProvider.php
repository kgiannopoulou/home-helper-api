<?php

namespace App\Providers;

use App\Ai\ChatModel;
use App\Ai\Providers\ClaudeChatModel;
use App\Ai\Providers\OfflineChatModel;
use App\Ai\Tools\Computers\GetComputers;
use App\Ai\Tools\Computers\RunComputerCommand;
use App\Ai\Tools\Finance\GetSpendingSummary;
use App\Ai\Tools\Finance\GetUpcomingBills;
use App\Ai\Tools\Finance\ListExpenses;
use App\Ai\Tools\Household\GetHouseholdOverview;
use App\Ai\Tools\Household\GetTodayPlan;
use App\Ai\Tools\Memory\ForgetFact;
use App\Ai\Tools\Memory\GetActivityTimeline;
use App\Ai\Tools\Memory\GetDailyBriefing;
use App\Ai\Tools\Memory\GetHouseholdSummaries;
use App\Ai\Tools\Memory\ReadNote;
use App\Ai\Tools\Memory\RememberFact;
use App\Ai\Tools\Memory\SaveNote;
use App\Ai\Tools\Memory\SearchHouseholdMemory;
use App\Ai\Tools\Plants\GetPlantCareToday;
use App\Ai\Tools\Plants\GetWeatherForecast;
use App\Ai\Tools\Plants\ListHomeMaintenance;
use App\Ai\Tools\Plants\ListPlantAlerts;
use App\Ai\Tools\Plants\LogPlantCare;
use App\Ai\Tools\Shopping\AddShoppingItem;
use App\Ai\Tools\Shopping\CheckOffShoppingItem;
use App\Ai\Tools\Shopping\GetKitchenInventory;
use App\Ai\Tools\Shopping\GetMealPlan;
use App\Ai\Tools\Shopping\GetShoppingList;
use App\Ai\Tools\Shopping\SuggestRecipes;
use App\Ai\Tools\SmartHome\ControlDevice;
use App\Ai\Tools\SmartHome\GetSmartHome;
use App\Ai\Tools\Tasks\AddTodo;
use App\Ai\Tools\Tasks\CompleteTodo;
use App\Ai\Tools\Tasks\ListOverdueChores;
use App\Ai\Tools\Tasks\ListTodos;
use App\Ai\Tools\Tasks\ListUpcomingEvents;
use App\Ai\Tools\Tasks\MarkChoreDone;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Memory\Embedder;
use App\Memory\ExtractiveSummarizer;
use App\Memory\HashingEmbedder;
use App\Memory\MemoryObserver;
use App\Memory\ModelSummarizer;
use App\Memory\Summarizer;
use App\Memory\VoyageEmbedder;
use App\Models\AiConversation;
use App\Models\HouseholdFact;
use App\Models\HouseholdSummary;
use App\Models\Note;
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
        // What to cook with what's there (Phase 7, day 89)
        SuggestRecipes::class,
        // What's planned to eat (day 90)
        GetMealPlan::class,
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
        // Memory and household intelligence (Phase 3, days 31-41)
        SearchHouseholdMemory::class,
        ReadNote::class,
        GetActivityTimeline::class,
        GetHouseholdSummaries::class,
        GetDailyBriefing::class,
        RememberFact::class,
        ForgetFact::class,
        SaveNote::class,
        // The household's computers, through the local agent (Phase 5, day 65)
        GetComputers::class,
        RunComputerCommand::class,
        // The smart home through Home Assistant (Phase 6, day 83)
        GetSmartHome::class,
        ControlDevice::class,
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

        // Memory (docs/memory.md): how text becomes vectors, and who writes conversation summaries
        $this->app->bind(Embedder::class, fn () => match (config('homehelper.memory.embedder')) {
            'local' => new HashingEmbedder((int) config('homehelper.memory.dimensions')),
            'voyage' => new VoyageEmbedder(
                apiKey: config('services.voyage.api_key'),
                model: (string) config('homehelper.memory.voyage_model'),
                dimensions: (int) config('homehelper.memory.dimensions'),
                timeout: (int) config('services.voyage.timeout'),
            ),
            default => throw new InvalidArgumentException('MEMORY_EMBEDDER must be local or voyage.'),
        });

        $this->app->bind(Summarizer::class, fn () => config('homehelper.ai.provider') === 'claude'
            // Summarising needs little thinking: the same model at low effort, short output
            ? new ModelSummarizer(new ClaudeChatModel(
                apiKey: config('services.anthropic.api_key'),
                model: (string) config('homehelper.ai.model'),
                effort: 'low',
                maxTokens: 2000,
                timeout: (int) config('services.anthropic.timeout'),
            ))
            : new ExtractiveSummarizer);
    }

    public function boot(): void
    {
        foreach ([Note::class, HouseholdFact::class, HouseholdSummary::class, AiConversation::class] as $model) {
            $model::observe(MemoryObserver::class);
        }
    }
}
