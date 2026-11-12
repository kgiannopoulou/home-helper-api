<?php

namespace App\Http\Controllers\Api\Agent;

use App\Agents\Releases;
use App\Models\AgentRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/agent/v1/releases/{release}/download (agent/docs/updates.md): the
 * release file, for an agent of its platform. Like every agent response it is
 * signed over its body; the agent then checks size, SHA-512 and the Ed25519
 * signature itself.
 */
class ReleaseDownloadController extends AgentController
{
    public function __invoke(Request $request, string $release): Response
    {
        $agent = $this->agent($request);
        $row = AgentRelease::query()->where('platform', $agent->platform)->whereNotNull('path')->findOrFail($release);

        $bytes = Storage::disk(Releases::DISK)->get((string) $row->path);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.basename((string) $row->path).'"',
        ]);
    }
}
