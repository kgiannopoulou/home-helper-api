<?php

namespace App\Http\Requests\Api;

use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validates a row for one household. One class covers both create and update:
 * POST and PUT need every required field, PATCH only checks the fields sent.
 */
abstract class HouseholdDataRequest extends FormRequest
{
    /**
     * Rules as for creating a row.
     *
     * @return array<string, array<int, mixed>>
     */
    abstract protected function fields(): array;

    public function authorize(): bool
    {
        // Policies decide access in the controller
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->fields();

        if ($this->isMethod('PATCH')) {
            foreach ($rules as $field => $fieldRules) {
                // A field that goes with another (bed and wake times) must still be checked when absent,
                // so its required_with rule can ask for it; 'sometimes' would skip it
                $pair = collect($fieldRules)->contains(fn ($rule) => is_string($rule) && str_starts_with($rule, 'required_with:'));
                $rules[$field] = collect($fieldRules)
                    ->map(fn ($rule) => $rule === 'required' ? ($pair ? null : 'sometimes') : $rule)
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        return $rules;
    }

    /**
     * Timestamps come from phones in their own time zone ("…T13:00:00+03:00").
     * Eloquent would store the 13:00 and drop the offset, so they become UTC first.
     *
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();

        foreach ($this->fields() as $field => $rules) {
            if (in_array('date', $rules, true) && isset($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->utc();
            }
        }

        return data_get($data, $key, $default);
    }

    protected function household(): Household
    {
        return $this->route('household');
    }

    /**
     * The id must be a live row of the same household, so nobody can link
     * their expense to another household's bill.
     */
    protected function inHousehold(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('household_id', $this->household()->id)
            ->whereNull('deleted_at');
    }

    protected function member(): Exists
    {
        return Rule::exists('household_user', 'user_id')
            ->where('household_id', $this->household()->id);
    }

    /**
     * @return array<int, mixed>
     */
    protected function money(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];
    }
}
