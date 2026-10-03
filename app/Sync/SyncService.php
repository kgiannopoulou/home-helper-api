<?php

namespace App\Sync;

use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Swaps changes with one phone, last write wins:
 * - an incoming row is stored only if its updated_at is newer than the stored one
 *   (a tie keeps what is stored, so every phone ends up with the same row)
 * - a deleted row arrives with deleted_at set and is soft-deleted
 * - the reply has every row of the household stored since the phone's last sync,
 *   deleted ones included, plus the stored version of any row the phone was behind on
 *
 * One bad row is rejected on its own; the rest of the batch still syncs.
 */
class SyncService
{
    /**
     * Rows changed shortly before a sync may still be committing when the reply is read;
     * the next sync starts this much earlier so it can't miss them. Getting a row twice is harmless.
     */
    public const OVERLAP_SECONDS = 5;

    /** id renames this request: collection => [phone id => stored id] */
    private array $remapped = [];

    /** @var list<array{collection: string, id: string, reason: string, errors?: array<string, list<string>>}> */
    private array $rejected = [];

    /** collection => ids stored as the phone sent them (no need to send back) */
    private array $accepted = [];

    /** collection => ids the phone is behind on (send the stored row) */
    private array $sendBack = [];

    private CarbonImmutable $now;

    public function __construct(private Household $household, private User $user) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $changes
     * @return array{since: string, changes: array<string, list<array<string, mixed>>>, remapped: array<string, array<string, string>>, rejected: list<array<string, mixed>>}
     */
    public function sync(?string $since, array $changes): array
    {
        $this->now = CarbonImmutable::now();
        // The next sync's starting point comes from the same clock that sets synced_at: MySQL's
        $dbNow = CarbonImmutable::parse(DB::selectOne('SELECT NOW(3) AS now')->now, 'UTC');

        DB::transaction(function () use ($changes) {
            foreach (Collections::all() as $name => $collection) {
                $rows = $changes[$name] ?? [];
                // Deletions first: replacing a night's sleep deletes the old row before the new one takes its date
                usort($rows, fn ($a, $b) => empty($a['deleted_at']) <=> empty($b['deleted_at']));
                foreach ($rows as $row) {
                    $this->apply($collection, is_array($row) ? $row : []);
                }
            }
        });

        return [
            'since' => $dbNow->subSeconds(self::OVERLAP_SECONDS)->format('Y-m-d\TH:i:s.vP'),
            'changes' => $this->changedSince($since),
            'remapped' => (object) $this->remapped,
            'rejected' => $this->rejected,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function apply(SyncCollection $c, array $row): void
    {
        $envelope = Validator::make($row, [
            'id' => ['required', 'string', 'max:26', 'regex:/^[0-9A-Za-z_-]+$/'],
            'updated_at' => ['required', 'date'],
            'deleted_at' => ['nullable', 'date'],
        ]);
        if ($envelope->fails()) {
            $this->reject($c, (string) ($row['id'] ?? ''), 'invalid', $envelope->errors()->toArray());

            return;
        }

        $phoneId = $row['id'];
        $deleted = ! empty($row['deleted_at']);
        // A device clock in the future would otherwise win every conflict for as long as it's ahead
        $updatedAt = $this->utc($row['updated_at'])->min($this->now);

        // Ids the server renamed earlier in this request (a room that joined an existing "Kitchen")
        foreach ($c->references as $column => $parent) {
            if (isset($row[$column], $this->remapped[$parent][$row[$column]])) {
                $row[$column] = $this->remapped[$parent][$row[$column]];
            }
        }

        // Looked up across all households, so a clash with someone else's id is caught here
        $existing = (new $c->model)->newQuery()->withTrashed()->find($phoneId);
        if ($existing && ! $this->owns($c, $existing)) {
            // Someone else's row with this id: never touch it, and don't say whose it is
            $this->reject($c, $phoneId, 'forbidden');

            return;
        }

        if (! $deleted) {
            $validator = Validator::make($row, $c->request::rulesFor($this->household));
            if ($validator->fails()) {
                $this->reject($c, $phoneId, 'invalid', $validator->errors()->toArray());

                return;
            }
            $fields = $this->timestampsToUtc($c, $validator->validated());
        }

        if (! $existing && ! $deleted && $c->naturalKey) {
            $existing = $this->sameThing($c, $fields);
            if ($existing) {
                $this->remapped[$c->name][$phoneId] = $existing->getKey();
            }
        }

        if (! $existing && $deleted) {
            // Deleted before it was ever synced: nothing to do
            $this->accepted[$c->name][] = $phoneId;

            return;
        }

        if ($existing && $existing->updated_at->greaterThanOrEqualTo($updatedAt)) {
            // Older (or as old) as what's stored: the phone gets the stored row instead
            $this->sendBack[$c->name][] = $existing->getKey();

            return;
        }

        $model = $existing ?? new $c->model;
        if ($deleted) {
            $model->forceFill(['deleted_at' => $this->utc($row['deleted_at'])]);
        } else {
            $model->forceFill([...$fields, 'deleted_at' => null]);
            if (! $existing) {
                $model->forceFill(['id' => $phoneId, 'household_id' => $this->household->id, 'created_at' => $this->now]);
                if ($c->personal) {
                    $model->forceFill(['user_id' => $this->user->id]);
                } elseif ($c->creator && empty($fields[$c->creator])) {
                    $model->forceFill([$c->creator => $this->user->id]);
                }
            }
        }
        $model->forceFill(['updated_at' => $updatedAt]);
        $model->timestamps = false;

        try {
            DB::transaction(fn () => $model->save());
        } catch (UniqueConstraintViolationException) {
            $this->reject($c, $phoneId, 'conflict');

            return;
        }

        if ($model instanceof InventoryItem && ! $deleted && isset($row['purchases']) && is_array($row['purchases'])) {
            $this->syncPurchases($model, $row['purchases']);
        }

        $this->accepted[$c->name][] = $model->getKey();
    }

    /**
     * The phone keeps an item's purchase days as a list; here they are rows.
     * Add the days that are new, soft-delete the ones that were removed.
     *
     * @param  array<int, mixed>  $days
     */
    private function syncPurchases(InventoryItem $item, array $days): void
    {
        $wanted = collect($days)
            ->filter(fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))
            ->countBy()
            ->all();

        foreach ($item->purchases()->get() as $purchase) {
            $day = $purchase->bought_on->toDateString();
            if (($wanted[$day] ?? 0) > 0) {
                $wanted[$day]--;
            } else {
                $purchase->delete();
            }
        }

        foreach ($wanted as $day => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->household->purchases()->create(['inventory_item_id' => $item->id, 'bought_on' => $day]);
            }
        }
    }

    /**
     * Every row stored since the phone's last sync, plus the ones it was behind on,
     * minus the ones it just sent (it already has them).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function changedSince(?string $since): array
    {
        $out = [];
        foreach (Collections::all() as $name => $c) {
            $sendBack = $this->sendBack[$name] ?? [];
            $accepted = array_diff($this->accepted[$name] ?? [], $sendBack);

            $rows = $this->query($c)
                ->withTrashed()
                ->where(fn ($q) => $q
                    ->when($since !== null, fn ($q) => $q->where('synced_at', '>', $this->utc($since)), fn ($q) => $q->whereRaw('1 = 1'))
                    ->orWhereIn('id', $sendBack))
                ->when($accepted, fn ($q) => $q->whereNotIn('id', $accepted))
                ->when($name === 'inventory_items', fn ($q) => $q->with('purchases'))
                ->when($name === 'chores', fn ($q) => $q->with('lastCompletion'))
                ->orderBy('id')
                ->get();

            if ($rows->isNotEmpty()) {
                $out[$name] = $rows->map(fn (Model $m) => $this->serialize($c, $m))->all();
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SyncCollection $c, Model $m): array
    {
        $row = (new $c->resource($m))->resolve();
        $row['updated_at'] = $m->updated_at?->format('Y-m-d\TH:i:s.vP');
        $row['deleted_at'] = $m->deleted_at?->format('Y-m-d\TH:i:s.vP');

        if ($m instanceof InventoryItem) {
            $row['purchases'] = $m->purchases->map(fn ($p) => $p->bought_on->toDateString())->sort()->values()->all();
        }
        if ($c->name === 'chores') {
            $row['last_done_at'] = $m->lastCompletion?->done_at?->toIso8601String();
        }

        return $row;
    }

    private function query(SyncCollection $c)
    {
        /** @var Model&SoftDeletes $model */
        $model = new $c->model;

        return $model->newQuery()
            ->where('household_id', $this->household->id)
            ->when($c->personal, fn ($q) => $q->where('user_id', $this->user->id));
    }

    private function owns(SyncCollection $c, Model $row): bool
    {
        return $row->household_id === $this->household->id
            && (! $c->personal || $row->user_id === $this->user->id);
    }

    /**
     * A live row of this household (and person) with the same natural key.
     *
     * @param  array<string, mixed>  $fields
     */
    private function sameThing(SyncCollection $c, array $fields): ?Model
    {
        $query = $this->query($c);
        foreach ($c->naturalKey as $column) {
            $value = $fields[$column] ?? null;
            if ($value === null) {
                return null; // an expense that isn't from a bill is never "the same" as another
            }
            $query->where($column, $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function timestampsToUtc(SyncCollection $c, array $fields): array
    {
        foreach ($c->request::rulesFor($this->household) as $field => $rules) {
            if (in_array('date', $rules, true) && isset($fields[$field])) {
                $fields[$field] = $this->utc($fields[$field]);
            }
        }

        return $fields;
    }

    private function utc(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function reject(SyncCollection $c, string $id, string $reason, array $errors = []): void
    {
        $this->rejected[] = array_filter(['collection' => $c->name, 'id' => $id, 'reason' => $reason, 'errors' => $errors ?: null]);
    }
}
