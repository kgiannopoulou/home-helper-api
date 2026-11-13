<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\SmartHomeAction;
use App\SmartHome\Control;
use App\SmartHome\DevicePresenter;
use App\SmartHome\HomeSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The smart home for the phone (docs/smart-home.md#api): the imported
 * devices with their states, and the same controls as the dashboard.
 */
class SmartHomeController extends Controller
{
    /** GET /api/households/{household}/smart-home */
    public function index(Household $household): JsonResponse
    {
        return response()->json([
            'summary' => HomeSummary::for($household),
            'devices' => $household->smartDevices()->active()->with('room')->orderBy('name')->get()
                ->map(fn (SmartDevice $d) => DevicePresenter::toArray($d))->values(),
        ]);
    }

    /** POST /api/households/{household}/smart-home/approvals/{action}/approve|decline: the owner answers from the phone */
    public function decide(Request $request, Household $household, string $action, Control $control): JsonResponse
    {
        $row = SmartHomeAction::query()->where('household_id', $household->id)->findOrFail($action);
        $row = $request->route()?->getName() === 'api.smart-home.approvals.approve'
            ? $control->approve($row, $request->user())
            : $control->decline($row, $request->user());

        return response()->json(['action' => ['id' => $row->id, 'status' => $row->status, 'error' => $row->error]]);
    }

    /** POST /api/households/{household}/smart-home/devices/{device}/actions {action, brightness?} */
    public function action(Request $request, Household $household, string $device, Control $control): JsonResponse
    {
        // Looked up inside the household: another household's id is a 404
        $device = $household->smartDevices()->findOrFail($device);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:turn_on,turn_off,toggle,set_brightness,lock,unlock'],
            'brightness' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $params = isset($data['brightness']) ? ['brightness' => (int) $data['brightness']] : [];

        $action = $control->request($device, $request->user(), $data['action'], $params, 'api');
        $status = match ($action->status) {
            'done' => 200,
            'awaiting_approval' => 202,
            default => 502,
        };

        return response()->json([
            'action' => ['id' => $action->id, 'status' => $action->status, 'error' => $action->error],
            'device' => DevicePresenter::toArray($device->fresh(['room'])),
        ], $status);
    }
}
