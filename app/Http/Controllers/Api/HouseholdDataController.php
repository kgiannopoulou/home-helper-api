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

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $rows = $this->query($household)
            ->when($this->dateColumn && isset($filters['from']), fn (Builder $q) => $q->where($this->dateColumn, '>=', $filters['from']))
            // Before the next day, so it works for DATE and TIMESTAMP columns and can use the index
            ->when($this->dateColumn && isset($filters['to']), fn (Builder $q) => $q->where($this->dateColumn, '<', Carbon::parse($filters['to'])->addDay()->toDateString()))
            ->when($this->dateColumn, fn (Builder $q) => $q->orderByDesc($this->dateColumn), fn (Builder $q) => $q->orderBy($this->orderBy))
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
