<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A home whose members share money, kitchen, shopping, chores and planner data.
 *
 * @property string $id
 * @property string $name
 * @property string $currency
 * @property float|null $latitude
 * @property float|null $longitude
 */
#[Fillable(['name', 'currency', 'latitude', 'longitude'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /** Set where the home is, so plant care can read the weather */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * The household_user rows themselves, with their role.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function addMember(User $user, HouseholdRole $role = HouseholdRole::Member): void
    {
        $this->users()->attach($user, ['role' => $role]);
    }

    /** @return HasMany<Invite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<RecurringBill, $this> */
    public function recurringBills(): HasMany
    {
        return $this->hasMany(RecurringBill::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return HasMany<InventoryItem, $this> */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return HasMany<ShoppingItem, $this> */
    public function shoppingItems(): HasMany
    {
        return $this->hasMany(ShoppingItem::class);
    }

    /** @return HasMany<ShoppingTrip, $this> */
    public function shoppingTrips(): HasMany
    {
        return $this->hasMany(ShoppingTrip::class);
    }

    /** @return HasMany<ItemPrice, $this> */
    public function itemPrices(): HasMany
    {
        return $this->hasMany(ItemPrice::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<Chore, $this> */
    public function chores(): HasMany
    {
        return $this->hasMany(Chore::class);
    }

    /** @return HasMany<ChoreCompletion, $this> */
    public function choreCompletions(): HasMany
    {
        return $this->hasMany(ChoreCompletion::class);
    }

    /** @return HasMany<Supply, $this> */
    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }

    /** @return HasMany<FoodEntry, $this> */
    public function foodEntries(): HasMany
    {
        return $this->hasMany(FoodEntry::class);
    }

    /** @return HasMany<WaterEntry, $this> */
    public function waterEntries(): HasMany
    {
        return $this->hasMany(WaterEntry::class);
    }

    /** @return HasMany<SleepEntry, $this> */
    public function sleepEntries(): HasMany
    {
        return $this->hasMany(SleepEntry::class);
    }

    /** @return HasMany<Workout, $this> */
    public function workouts(): HasMany
    {
        return $this->hasMany(Workout::class);
    }

    /** @return HasMany<StepCount, $this> */
    public function stepCounts(): HasMany
    {
        return $this->hasMany(StepCount::class);
    }

    /** @return HasMany<Weight, $this> */
    public function weights(): HasMany
    {
        return $this->hasMany(Weight::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<Todo, $this> */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    /** @return HasMany<AdminItem, $this> */
    public function adminItems(): HasMany
    {
        return $this->hasMany(AdminItem::class);
    }

    /** @return HasMany<Plant, $this> */
    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class);
    }

    /** @return HasMany<PlantCareLog, $this> */
    public function plantCareLogs(): HasMany
    {
        return $this->hasMany(PlantCareLog::class);
    }

    /** @return HasMany<PlantAlert, $this> */
    public function plantAlerts(): HasMany
    {
        return $this->hasMany(PlantAlert::class);
    }

    /** @return HasMany<HouseholdFact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(HouseholdFact::class);
    }

    /** @return HasMany<Agent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /** @return HasMany<AgentEnrollmentCode, $this> */
    public function agentEnrollmentCodes(): HasMany
    {
        return $this->hasMany(AgentEnrollmentCode::class);
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** @return HasMany<HouseholdSummary, $this> */
    public function summaries(): HasMany
    {
        return $this->hasMany(HouseholdSummary::class);
    }

    /** @return HasMany<JobRun, $this> */
    public function jobRuns(): HasMany
    {
        return $this->hasMany(JobRun::class);
    }
}
