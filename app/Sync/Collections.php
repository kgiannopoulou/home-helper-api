<?php

namespace App\Sync;

use App\Http\Requests\Api;
use App\Http\Resources;
use App\Models;

/**
 * Everything the phone syncs, parents before children so that a room arrives
 * before its chores in the same request.
 */
final class Collections
{
    /**
     * @return array<string, SyncCollection>
     */
    public static function all(): array
    {
        $list = [
            new SyncCollection('recurring_bills', Models\RecurringBill::class, Api\RecurringBillRequest::class, Resources\RecurringBillResource::class,
                naturalKey: ['name']),
            new SyncCollection('expenses', Models\Expense::class, Api\ExpenseRequest::class, Resources\ExpenseResource::class,
                // Both phones add this month's rent from the same bill: one expense, not two
                creator: 'user_id', naturalKey: ['recurring_bill_id', 'date'], references: ['recurring_bill_id' => 'recurring_bills']),
            new SyncCollection('shopping_trips', Models\ShoppingTrip::class, Api\ShoppingTripRequest::class, Resources\ShoppingTripResource::class,
                creator: 'user_id'),
            new SyncCollection('shopping_items', Models\ShoppingItem::class, Api\ShoppingItemRequest::class, Resources\ShoppingItemResource::class,
                creator: 'added_by'),
            new SyncCollection('inventory_items', Models\InventoryItem::class, Api\InventoryItemRequest::class, Resources\InventoryItemResource::class,
                naturalKey: ['name']),
            new SyncCollection('rooms', Models\Room::class, Api\RoomRequest::class, Resources\RoomResource::class,
                naturalKey: ['name']),
            new SyncCollection('chores', Models\Chore::class, Api\ChoreRequest::class, Resources\ChoreResource::class,
                naturalKey: ['room_id', 'name'], references: ['room_id' => 'rooms']),
            new SyncCollection('chore_completions', Models\ChoreCompletion::class, Api\ChoreCompletionRequest::class, Resources\ChoreCompletionResource::class,
                creator: 'user_id', references: ['chore_id' => 'chores']),
            new SyncCollection('supplies', Models\Supply::class, Api\SupplyRequest::class, Resources\SupplyResource::class,
                naturalKey: ['name']),
            new SyncCollection('food_entries', Models\FoodEntry::class, Api\FoodEntryRequest::class, Resources\FoodEntryResource::class,
                personal: true),
            new SyncCollection('water_entries', Models\WaterEntry::class, Api\WaterEntryRequest::class, Resources\WaterEntryResource::class,
                personal: true),
            new SyncCollection('sleep_entries', Models\SleepEntry::class, Api\SleepEntryRequest::class, Resources\SleepEntryResource::class,
                personal: true, naturalKey: ['date']),
            new SyncCollection('workouts', Models\Workout::class, Api\WorkoutRequest::class, Resources\WorkoutResource::class,
                personal: true),
            new SyncCollection('step_counts', Models\StepCount::class, Api\StepCountRequest::class, Resources\StepCountResource::class,
                personal: true, naturalKey: ['date']),
            new SyncCollection('weights', Models\Weight::class, Api\WeightRequest::class, Resources\WeightResource::class,
                personal: true, naturalKey: ['date']),
            new SyncCollection('events', Models\Event::class, Api\EventRequest::class, Resources\EventResource::class,
                creator: 'user_id'),
            new SyncCollection('todos', Models\Todo::class, Api\TodoRequest::class, Resources\TodoResource::class,
                creator: 'user_id'),
            new SyncCollection('admin_items', Models\AdminItem::class, Api\AdminItemRequest::class, Resources\AdminItemResource::class),
        ];

        return array_column(array_map(fn (SyncCollection $c) => [$c->name, $c], $list), 1, 0);
    }
}
