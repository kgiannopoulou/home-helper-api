<?php

namespace App\Sync;

use App\Http\Requests\Api\HouseholdDataRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How one kind of row is synced.
 */
final readonly class SyncCollection
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<HouseholdDataRequest>  $request  rules for a live row
     * @param  class-string<JsonResource>  $resource  shape of a row sent back
     * @param  bool  $personal  only the caller's own rows (food, sleep…)
     * @param  string|null  $creator  column set to the caller when a new row doesn't say who
     * @param  list<string>  $naturalKey  columns that identify the same thing made on two phones
     *                                    ("Kitchen", "Milk"): a new id with the same key joins the existing row
     * @param  array<string, string>  $references  column => collection it points to, for ids renamed this way
     */
    public function __construct(
        public string $name,
        public string $model,
        public string $request,
        public string $resource,
        public bool $personal = false,
        public ?string $creator = null,
        public array $naturalKey = [],
        public array $references = [],
    ) {}
}
