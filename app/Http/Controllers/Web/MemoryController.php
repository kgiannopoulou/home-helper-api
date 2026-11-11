<?php

namespace App\Http\Controllers\Web;

use App\Enums\FactKind;
use App\Enums\NoteKind;
use App\Memory\DocumentText;
use App\Memory\KnowledgeBase;
use App\Memory\MemoryPresenter;
use App\Models\HouseholdFact;
use App\Models\Note;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The Memory page (resources/js/pages/memory.tsx): what Home Helper remembers,
 * the household's notes and documents, and search over all of it.
 */
class MemoryController extends WebController
{
    public function show(Request $request, KnowledgeBase $knowledge): Response
    {
        $household = $this->household($request);
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('memory', [
            'facts' => HouseholdFact::query()->visibleTo($household, $user)->with(['about', 'user:id,name'])->latest()->limit(200)->get()
                ->map(fn ($f) => MemoryPresenter::fact($f, $user))->all(),
            'notes' => Note::query()->visibleTo($household, $user)->with('user:id,name')->orderByDesc('updated_at')->limit(200)->get()
                ->map(fn ($n) => MemoryPresenter::note($n, $user))->all(),
            'members' => $household->users()->orderBy('name')->get(['users.id', 'users.name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'kinds' => FactKind::values(),
            'query' => $q,
            'results' => $q === '' ? null : $knowledge->search($household, $user, mb_substr($q, 0, 300), 8),
        ]);
    }

    public function storeFact(Request $request): RedirectResponse
    {
        $household = $this->household($request);
        $data = $request->validate([
            'kind' => ['required', Rule::enum(FactKind::class)],
            'content' => ['required', 'string', 'min:3', 'max:500'],
            'about_user_id' => ['nullable', 'integer', Rule::exists('household_user', 'user_id')->where('household_id', $household->id)],
            'private' => ['boolean'],
        ]);
        $household->facts()->create($data + ['user_id' => $request->user()->id, 'source' => 'person']);

        return to_route('memory');
    }

    public function destroyFact(Request $request, string $fact): RedirectResponse
    {
        HouseholdFact::query()->visibleTo($this->household($request), $request->user())->findOrFail($fact)->delete();

        return to_route('memory');
    }

    public function storeNote(Request $request, DocumentText $extractor): RedirectResponse
    {
        $household = $this->household($request);
        $data = $request->validate([
            'title' => [$request->hasFile('file') ? 'nullable' : 'required', 'string', 'max:150'],
            'body' => ['required_without:file', 'nullable', 'string', 'max:100000'],
            'file' => ['nullable', 'file', 'max:'.config('homehelper.memory.max_upload_kb'), 'extensions:'.implode(',', DocumentText::MIMES)],
            'private' => ['boolean'],
        ]);

        $note = ['user_id' => $request->user()->id, 'private' => (bool) ($data['private'] ?? false)];
        if ($file = $request->file('file')) {
            try {
                $body = $extractor->extract($file);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
            $note += [
                'kind' => NoteKind::Document,
                'title' => ($data['title'] ?? null) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'body' => $body,
                'file_path' => $file->store("notes/{$household->id}", 'local'),
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 150),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        } else {
            $note += ['kind' => NoteKind::Note, 'title' => $data['title'], 'body' => $data['body']];
        }
        $household->notes()->create($note);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Home Helper can find it now.']);

        return to_route('memory');
    }

    public function destroyNote(Request $request, string $note): RedirectResponse
    {
        $note = Note::query()->visibleTo($this->household($request), $request->user())->findOrFail($note);
        if ($note->file_path) {
            Storage::disk('local')->delete($note->file_path);
        }
        $note->delete();

        return to_route('memory');
    }
}
