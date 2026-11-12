<?php

namespace App\Http\Controllers\Api\Agent;

use App\Agents\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/agent/v1/inventory (agent/docs/protocol.md#post-inventory):
 * what the computer has. Sent on start, when it changes, and when the
 * heartbeat reply asks for it.
 */
class InventoryController extends AgentController
{
    public function __invoke(Request $request, Inventory $inventory): Response
    {
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:255'],
            'os' => ['required', 'array:name,version'],
            'os.name' => ['required', 'string', 'max:40'],
            'os.version' => ['nullable', 'string', 'max:120'],
            'cpu' => ['nullable', 'array:model,cores'],
            'cpu.model' => ['nullable', 'string', 'max:120'],
            'cpu.cores' => ['nullable', 'integer', 'between:0,1024'],
            'memory_bytes' => ['nullable', 'integer', 'min:0'],
            'disks' => ['present', 'array', 'max:32'],
            'disks.*' => ['array:mount,total_bytes,filesystem'],
            'disks.*.mount' => ['required', 'string', 'max:255'],
            'disks.*.total_bytes' => ['required', 'integer', 'min:0'],
            'disks.*.filesystem' => ['nullable', 'string', 'max:40'],
            'audio' => ['present', 'array', 'max:64'],
            'audio.*' => ['array:id,name,direction,default,state'],
            'audio.*.id' => ['required', 'string', 'max:255'],
            'audio.*.name' => ['required', 'string', 'max:255'],
            'audio.*.direction' => ['required', 'in:input,output'],
            'audio.*.default' => ['required', 'boolean'],
            'audio.*.state' => ['required', 'in:active,unplugged,disabled,unknown'],
            'commands' => ['present', 'array', 'max:50'],
            'commands.*' => ['array:key,description,sensitive,params'],
            'commands.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,49}$/', 'distinct'],
            'commands.*.description' => ['required', 'string', 'max:200'],
            'commands.*.sensitive' => ['required', 'boolean'],
            'commands.*.params' => ['present', 'array', 'max:10'],
            'commands.*.params.*' => ['array:name,description,pattern'],
            'commands.*.params.*.name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,29}$/'],
            'commands.*.params.*.description' => ['nullable', 'string', 'max:200'],
            'commands.*.params.*.pattern' => ['required', 'string', 'max:300'],
        ]);

        $inventory->store($this->agent($request), $data, $request->getContent());

        return response()->noContent();
    }
}
