<?php

namespace App\Http\Controllers\Web;

use App\Models\AutomationRule;
use App\Policies\SmartHomePolicy;
use App\SmartHome\Automation\RuleEngine;
use App\SmartHome\Automation\RuleValidator;
use App\SmartHome\Automation\Templates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Automations on the Smart home page (docs/smart-home.md#automations): the
 * owner adds, edits, switches off and removes them; anyone in the household
 * sees them and what they did.
 */
class AutomationsController extends WebController
{
    public function store(Request $request, RuleValidator $validator): RedirectResponse
    {
        $household = $this->household($request);
        $this->authorizeManage($request);
        $data = $validator->validate($household, $request->all());

        $rule = new AutomationRule($data + ['created_by' => $request->user()->id, 'template' => $request->string('template')->limit(60, '')->value() ?: null]);
        $rule->household_id = $household->id;
        $rule->save();

        return to_route('smart-home');
    }

    /**
     * Add a ready-made workflow (Templates): with the devices it suggested,
     * or the ones the owner picked instead (devices[role] = [ids])
     */
    public function template(Request $request, string $key, RuleValidator $validator): RedirectResponse
    {
        $household = $this->household($request);
        $this->authorizeManage($request);
        abort_unless(isset(Templates::ALL[$key]), 404);
        $request->validate(['devices' => ['sometimes', 'array'], 'devices.*' => ['array', 'max:5'], 'devices.*.*' => ['string', 'size:26']]);

        $suggestion = collect(Templates::suggestions($household))->firstWhere('key', $key);
        $devices = array_map(fn (array $picked) => array_column($picked, 'id'), $suggestion['devices']);
        $devices = array_replace($devices, array_intersect_key((array) $request->input('devices', []), Templates::ALL[$key]['needs']));
        if (array_diff_key(Templates::ALL[$key]['needs'], array_filter($devices))) {
            throw ValidationException::withMessages(['template' => 'This workflow needs: '.implode(', ', array_keys(Templates::ALL[$key]['needs'])).'.']);
        }

        $rules = array_map(fn (array $input) => $validator->validate($household, $input), Templates::rules($key, $devices));
        DB::transaction(function () use ($rules, $household, $request, $key) {
            foreach ($rules as $data) {
                $rule = new AutomationRule($data + ['created_by' => $request->user()->id, 'template' => $key]);
                $rule->household_id = $household->id;
                $rule->save();
            }
        });

        return to_route('smart-home');
    }

    public function update(Request $request, AutomationRule $rule, RuleValidator $validator): RedirectResponse
    {
        $this->inHousehold($request, $rule);
        $this->authorizeManage($request);

        // Just the switch, or the whole rule
        if ($request->keys() === ['enabled']) {
            $rule->forceFill(['enabled' => $request->boolean('enabled')])->save();
        } else {
            $rule->forceFill($validator->validate($this->household($request), $request->all()))->save();
        }

        return to_route('smart-home');
    }

    public function destroy(Request $request, AutomationRule $rule): RedirectResponse
    {
        $this->inHousehold($request, $rule);
        $this->authorizeManage($request);
        $rule->delete();

        return to_route('smart-home');
    }

    /** "Run now": the actions, without the trigger, cooldown or conditions; to try a rule out */
    public function run(Request $request, AutomationRule $rule, RuleEngine $engine): RedirectResponse
    {
        $this->inHousehold($request, $rule);
        $this->authorizeManage($request);
        $engine->fire($rule, 'Run now by '.$request->user()->name, manual: true);

        return to_route('smart-home');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless((new SmartHomePolicy)->manage($request->user(), $this->household($request)), 403);
    }

    private function inHousehold(Request $request, AutomationRule $rule): void
    {
        abort_unless($rule->household_id === $this->household($request)->id, 404);
    }
}
