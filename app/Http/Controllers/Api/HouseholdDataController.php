<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\HouseholdDataRequest;
use App\Models\Household;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD for one kind of household row, under /api/households/{household}/….
 *
 * Every query starts from the household's relation, so a row of another
 * household is never found (404), and the policy is checked on top of that.
 */
abstract class HouseholdDataController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    /** @var class-string<JsonResource> */
    protected string $resource;

    /** @var class-string<HouseholdDataRequest> */
    protected string $request;

    /** Column for ?from=&to= and newest-first order; null = no date filter */
    protected ?string $dateColumn = null;

    /** Order when there is no date column */
    protected string $orderBy = 'created_at';

    /** Personal logs: only the caller's own rows */
    protected bool $personal = false;

    /** Column set to the caller on create (who added it) */
    protected ?string $creatorColumn = null;

    public function index(Request $request, Household $household): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [$this->model, $household]);

        $sortable = $this->sortable();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'updated_since' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', Rule::in([...$sortable, ...array_map(fn ($c) => "-$c", $sortable)])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        // ?sort=amount is ascending, ?sort=-amount descending; the default is
        // newest first for dated modules, otherwise $orderBy
        $sort = $filters['sort'] ?? ($this->dateColumn ? "-{$this->dateColumn}" : $this->orderBy);
        $sortColumn = ltrim($sort, '-');

        $rows = $this->query($household)
            ->when($this->dateColumn && isset($filters['from']), fn (Builder $q) => $q->where($this->dateColumn, '>=', $filters['from']))
            // Before the next day, so it works for DATE and TIMESTAMP columns and can use the index
            ->when($this->dateColumn && isset($filters['to']), fn (Builder $q) => $q->where($this->dateColumn, '<', Carbon::parse($filters['to'])->addDay()->toDateString()))
            ->when(isset($filters['updated_since']), fn (Builder $q) => $q->where('updated_at', '>=', Carbon::parse($filters['updated_since'])->utc()))
            ->orderBy($sortColumn, str_starts_with($sort, '-') ? 'desc' : 'asc')
            // A unique tie-breaker keeps pages stable when many rows share a value
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return $this->resource::collection($rows);
    }

    public function store(Household $household): JsonResponse
    {
        Gate::authorize('create', [$this->model, $household]);

        $data = app($this->request)->validated();
        if ($this->personal) {
            $data['user_id'] = auth()->id();
        }
        if ($this->creatorColumn) {
            $data[$this->creatorColumn] ??= auth()->id();
        }

        $row = $this->relation($household)->create($data);

        return (new $this->resource($row->refresh()))->response()->setStatusCode(201);
    }

    public function show(Household $household, string $id): JsonResource
    {
        $row = $this->find($household, $id);
        Gate::authorize('view', $row);

        return new $this->resource($row);
    }

    public function update(Household $household, string $id): JsonResource
    {
        $row = $this->find($household, $id);
        Gate::authorize('update', $row);

        $row->update(app($this->request)->validated());

        return new $this->resource($row->refresh());
    }

    public function destroy(Household $household, string $id): Response
    {
        $row = $this->find($household, $id);
        Gate::authorize('delete', $row);

        $row->delete();

        return response()->noContent();
    }

    /**
     * Columns a list can be sorted by: the date column, the default order
     * column, the timestamps and any module-specific extras.
     *
     * @return list<string>
     */
    protected function sortable(): array
    {
        return array_values(array_unique(array_filter([
            $this->dateColumn, $this->orderBy, 'created_at', 'updated_at', ...$this->extraSortable,
        ])));
    }

    /** More sortable columns for one module, e.g. ['amount'] */
    protected array $extraSortable = [];

    /**
     * @return HasMany<Model, Household>
     */
    protected function relation(Household $household): HasMany
    {
        return $household->{Str::camel(Str::plural(class_basename($this->model)))}();
    }

    /**
     * @return Builder<Model>
     */
    protected function query(Household $household): Builder
    {
        return $this->relation($household)
            ->getQuery()
            ->when($this->personal, fn (Builder $q) => $q->where('user_id', auth()->id()));
    }

    protected function find(Household $household, string $id): Model
    {
        // Someone else's personal log is "not found", not "forbidden": it doesn't leak that it exists
        return $this->query($household)->findOrFail($id);
    }
}
