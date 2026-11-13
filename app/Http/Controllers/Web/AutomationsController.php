<?php

namespace App\Http\Controllers\Web;

use App\Models\AutomationRule;
use App\Policies\SmartHomePolicy;
use App\SmartHome\Automation\RuleEngine;
use App\SmartHome\Automation\RuleValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
