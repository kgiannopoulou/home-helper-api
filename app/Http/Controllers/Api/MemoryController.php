<?php

namespace App\Http\Controllers\Api;

use App\Enums\FactKind;
use App\Enums\MemorySource;
use App\Enums\NoteKind;
use App\Http\Controllers\Controller;
use App\Memory\DocumentText;
use App\Memory\KnowledgeBase;
use App\Memory\MemoryPresenter;
use App\Models\Household;
use App\Models\HouseholdFact;
use App\Models\Note;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Long-term memory over the API (docs/memory.md#api): facts, notes and
 * documents, and search. Shared items can be changed by any member; a private
 * one only exists for its author (404 for everyone else).
 */
class MemoryController extends Controller
{
    /** The household's facts you can see, newest first. ?kind= */
    public function facts(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate(['kind' => ['sometimes', Rule::enum(FactKind::class)]]);

        $facts = HouseholdFact::query()->visibleTo($household, $request->user())->with(['about', 'user:id,name'])
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $facts->map(fn ($f) => MemoryPresenter::fact($f, $request->user()))->all()]);
    }

    public function storeFact(Request $request, Household $household): JsonResponse
    {
        $data = $this->validateFact($request, $household);
        $fact = $household->facts()->create($data + ['user_id' => $request->user()->id, 'source' => 'person']);

        return response()->json(['data' => MemoryPresenter::fact($fact->load(['about', 'user']), $request->user())], 201);
    }

    public function updateFact(Request $request, Household $household, string $fact): JsonResponse
    {
        $fact = HouseholdFact::query()->visibleTo($household, $request->user())->findOrFail($fact);
        $data = $this->validateFact($request, $household, partial: true);
        // Only its author may make a fact private (or public again)
        if (array_key_exists('private', $data) && $fact->user_id !== $request->user()->id) {
            unset($data['private']);
        }
        $fact->update($data);

        return response()->json(['data' => MemoryPresenter::fact($fact->load(['about', 'user']), $request->user())]);
    }

    public function destroyFact(Request $request, Household $household, string $fact): Response
    {
        HouseholdFact::query()->visibleTo($household, $request->user())->findOrFail($fact)->delete();

        return response()->noContent();
    }

    /** Notes and documents you can see, newest first. ?kind=note|document */
    public function notes(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate(['kind' => ['sometimes', Rule::enum(NoteKind::class)]]);

        $notes = Note::query()->visibleTo($household, $request->user())->with('user:id,name')
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('updated_at')->limit(200)->get();

        return response()->json(['data' => $notes->map(fn ($n) => MemoryPresenter::note($n, $request->user()))->all()]);
    }

    /**
     * A note (title + body as JSON), or a document (multipart: file, optional title).
     * Documents: .txt, .md or .pdf with a text layer, up to 5 MB.
     */
    public function storeNote(Request $request, Household $household, DocumentText $extractor): JsonResponse
    {
        if ($request->hasFile('file')) {
            $data = $request->validate([
                'file' => ['required', 'file', 'max:'.config('homehelper.memory.max_upload_kb'), 'extensions:'.implode(',', DocumentText::MIMES)],
                'title' => ['sometimes', 'string', 'max:150'],
                'private' => ['sometimes', 'boolean'],
            ]);
            $file = $request->file('file');
            try {
                $body = $extractor->extract($file);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
            $path = $file->store("notes/{$household->id}", 'local');

            $note = $household->notes()->create([
                'user_id' => $request->user()->id,
                'kind' => NoteKind::Document,
                'title' => $data['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'body' => $body,
                'file_path' => $path,
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 150),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'private' => (bool) ($data['private'] ?? false),
            ]);
        } else {
            $data = $request->validate([
                'title' => ['required', 'string', 'max:150'],
                'body' => ['required', 'string', 'max:100000'],
                'private' => ['sometimes', 'boolean'],
            ]);
            $note = $household->notes()->create($data + ['user_id' => $request->user()->id, 'kind' => NoteKind::Note]);
        }

        return response()->json(['data' => MemoryPresenter::note($note->load('user'), $request->user(), withBody: true)], 201);
    }

    public function showNote(Request $request, Household $household, string $note): JsonResponse
    {
        $note = Note::query()->visibleTo($household, $request->user())->with('user:id,name')->findOrFail($note);

        return response()->json(['data' => MemoryPresenter::note($note, $request->user(), withBody: true)]);
    }

    public function updateNote(Request $request, Household $household, string $note): JsonResponse
    {
        $note = Note::query()->visibleTo($household, $request->user())->findOrFail($note);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'body' => ['sometimes', 'string', 'max:100000'],
            'private' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('private', $data) && $note->user_id !== $request->user()->id) {
            unset($data['private']);
        }
        $note->update($data);

        return response()->json(['data' => MemoryPresenter::note($note->load('user'), $request->user(), withBody: true)]);
    }

    public function destroyNote(Request $request, Household $household, string $note): Response
    {
        $note = Note::query()->visibleTo($household, $request->user())->findOrFail($note);
        if ($note->file_path) {
            Storage::disk('local')->delete($note->file_path);
        }
        $note->delete();

        return response()->noContent();
    }

    /** The uploaded file of a document, as it was uploaded */
    public function download(Request $request, Household $household, string $note): StreamedResponse
    {
        $note = Note::query()->visibleTo($household, $request->user())->whereNotNull('file_path')->findOrFail($note);

        return Storage::disk('local')->download($note->file_path, $note->file_name);
    }

    /**
     * Hybrid search (meaning + keywords) over notes, documents, facts, your
     * conversation summaries and the household summaries.
     */
    public function search(Request $request, Household $household, KnowledgeBase $knowledge): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:300'],
            'sources' => ['sometimes', 'array'],
            'sources.*' => [Rule::enum(MemorySource::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'limit' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        return response()->json(['data' => $knowledge->search(
            $household,
            $request->user(),
            $data['q'],
            (int) ($data['limit'] ?? 8),
            isset($data['sources']) ? array_values(array_map(MemorySource::from(...), $data['sources'])) : null,
            isset($data['from']) ? CarbonImmutable::parse($data['from']) : null,
            isset($data['to']) ? CarbonImmutable::parse($data['to']) : null,
        )]);
    }

    /** @return array<string, mixed> */
    private function validateFact(Request $request, Household $household, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'kind' => [$required, Rule::enum(FactKind::class)],
            'content' => [$required, 'string', 'min:3', 'max:500'],
            'about_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('household_user', 'user_id')->where('household_id', $household->id)],
            'private' => ['sometimes', 'boolean'],
        ]);
    }
}
