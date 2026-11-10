<?php

use App\Ai\Assistant;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\HouseholdRole;
use App\Models\AiPendingAction;
use App\Models\Household;
use App\Models\ShoppingItem;
use App\Models\Todo;
use App\Models\User;
use App\Providers\AiServiceProvider;
use App\Support\HomeTime;

/*
 * The gate in front of every tool (day 27): existence, membership, the
 * household boundary, validation, and confirmation for anything that writes.
 */

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->user = User::factory()->create();
    $this->home->addMember($this->user, HouseholdRole::Owner);
    $this->conversation = app(Assistant::class)->start($this->home, $this->user);
    $this->ctx = new ToolContext($this->home, $this->user, HomeTime::today(), $this->conversation);
    $this->tools = app(ToolRegistry::class);
});

test('every tool in the provider is registered once, sorted, with a schema and a label', function () {
    $names = array_keys($this->tools->all());

    expect($names)->toHaveCount(count(AiServiceProvider::TOOLS))
        ->and($names)->toBe(collect($names)->sort()->values()->all());

    foreach ($this->tools->all() as $tool) {
        expect($tool->schema()['type'])->toBe('object')
            ->and($tool->label())->not->toBeEmpty()
            ->and(strlen($tool->description()))->toBeGreaterThan(40);
    }
});

test('the definitions sent to the model are stable, and say which tools change data', function () {
    $first = json_encode($this->tools->definitions());
    expect(json_encode(app(ToolRegistry::class)->definitions()))->toBe($first);

    $add = collect($this->tools->definitions())->firstWhere('name', 'add_shopping_item');
    expect($add['description'])->toContain('the person must confirm it');
});

test('an unknown tool is an error the model can read', function () {
    $outcome = $this->tools->call('delete_everything', [], $this->ctx);

    expect($outcome->isError)->toBeTrue()
        ->and($outcome->content['error'])->toContain('no tool called delete_everything')
        ->and($outcome->content['available'])->toContain('get_shopping_list');
});

test('someone who left the household gets nothing', function () {
    $this->home->users()->detach($this->user);

    $outcome = $this->tools->call('get_shopping_list', [], $this->ctx);

    expect($outcome->isError)->toBeTrue()->and($outcome->content['error'])->toBe('You are not a member of this household.');
});

test('invalid input comes back with the field errors', function () {
    $outcome = $this->tools->call('list_todos', ['status' => 'everything', 'limit' => 500], $this->ctx);

    expect($outcome->isError)->toBeTrue()
        ->and(array_keys($outcome->content['fields']))->toBe(['status', 'limit']);
});

test('an id from another household is refused, as if it didn\'t exist', function () {
    $theirs = Todo::factory()->for(Household::factory())->create();

    $outcome = $this->tools->call('complete_todo', ['todo_id' => $theirs->id], $this->ctx);

    expect($outcome->isError)->toBeTrue()->and($outcome->content['fields'])->toHaveKey('todo_id')
        ->and($theirs->fresh()->done_at)->toBeNull()
        ->and(AiPendingAction::count())->toBe(0);
});

test('a write is only proposed: nothing changes until the person confirms', function () {
    $outcome = $this->tools->call('add_shopping_item', ['name' => 'Oat milk', 'category' => 'drinks', 'quantity' => '2'], $this->ctx);

    expect($outcome->isError)->toBeFalse()
        ->and($outcome->content['status'])->toBe('awaiting_confirmation')
        ->and($outcome->content['proposed'])->toBe('Add "Oat milk" (2) to the shopping list')
        ->and(ShoppingItem::count())->toBe(0);

    $action = AiPendingAction::findOrFail($outcome->pendingActionId);
    expect($action->status)->toBe('pending')->and($action->user_id)->toBe($this->user->id);

    app(Assistant::class)->confirm($action, $this->user);

    expect($action->fresh()->status)->toBe('confirmed')
        ->and(ShoppingItem::where('name', 'Oat milk')->first())->household_id->toBe($this->home->id)
        ->and($this->conversation->messages()->where('role', 'event')->first()->text())->toBe('The person confirmed "Add "Oat milk" (2) to the shopping list". It is done.');
});

test('confirming checks everything again: the item already got added meanwhile', function () {
    $outcome = $this->tools->call('add_shopping_item', ['name' => 'Bread', 'category' => 'food'], $this->ctx);
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Bread', 'checked' => false]);

    $action = app(Assistant::class)->confirm(AiPendingAction::findOrFail($outcome->pendingActionId), $this->user);

    expect($action->status)->toBe('failed')
        ->and(ShoppingItem::where('name', 'Bread')->count())->toBe(1);
});

test('confirming after leaving the household does nothing', function () {
    $outcome = $this->tools->call('add_todo', ['title' => 'Fix the tap'], $this->ctx);
    $this->home->users()->detach($this->user);

    $action = app(Assistant::class)->confirm(AiPendingAction::findOrFail($outcome->pendingActionId), $this->user);

    expect($action->status)->toBe('failed')->and(Todo::count())->toBe(0);
});

test('a proposal expires', function () {
    $outcome = $this->tools->call('add_todo', ['title' => 'Fix the tap'], $this->ctx);
    $this->travel(config('homehelper.ai.confirmation_minutes') + 1)->minutes();

    $action = app(Assistant::class)->confirm(AiPendingAction::findOrFail($outcome->pendingActionId), $this->user);

    expect($action->status)->toBe('expired')->and(Todo::count())->toBe(0);
});

test('declining records it and changes nothing', function () {
    $outcome = $this->tools->call('add_todo', ['title' => 'Fix the tap'], $this->ctx);

    $action = app(Assistant::class)->decline(AiPendingAction::findOrFail($outcome->pendingActionId));

    expect($action->status)->toBe('declined')->and(Todo::count())->toBe(0)
        ->and($this->conversation->messages()->where('role', 'event')->first()->text())->toContain('declined');
});

test('with writes switched off, write tools disappear and are refused', function () {
    config(['homehelper.ai.allow_writes' => false]);

    expect(array_column($this->tools->definitions(), 'name'))->not->toContain('add_shopping_item')
        ->and($this->tools->call('add_shopping_item', ['name' => 'Milk', 'category' => 'food'], $this->ctx)->content['error'])
        ->toBe('The assistant may only read in this household.');
});

test('a tool that throws becomes an error result, not a crash', function () {
    $broken = new class extends Tool
    {
        public function name(): string
        {
            return 'broken';
        }

        public function label(): string
        {
            return 'Broke';
        }

        public function description(): string
        {
            return 'Always fails.';
        }

        public function run(array $input, ToolContext $context): array
        {
            throw new RuntimeException('database on fire');
        }
    };

    $outcome = (new ToolRegistry([$broken]))->call('broken', [], $this->ctx);

    expect($outcome->isError)->toBeTrue()->and($outcome->content['error'])->toContain('do not guess')
        ->and(json_encode($outcome->content))->not->toContain('database on fire');
});
