<?php

use App\Ai\Assistant;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Enums\FactKind;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdFact;
use App\Models\MemoryChunk;
use App\Models\Note;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScriptedChatModel;

/*
 * Days 31, 32, 34: household facts and preferences, notes and documents, and
 * the assistant using them (in its context, through its tools, and only
 * writing them after the person confirms).
 */

beforeEach(function () {
    $this->home = Household::factory()->create(['name' => 'Patission 12']);
    $this->me = User::factory()->create(['name' => 'Maria']);
    $this->alex = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->home->addMember($this->alex);
    $this->base = "/api/households/{$this->home->id}/memory";
});

test('facts over the API: add, list, change, delete; private ones exist only for their author', function () {
    Sanctum::actingAs($this->me);
    $shared = $this->postJson("{$this->base}/facts", ['kind' => 'preference', 'content' => 'Doesn\'t eat meat.', 'about_user_id' => $this->alex->id])
        ->assertCreated()->assertJsonPath('data.about.name', 'Alex')->assertJsonPath('data.source', 'person')->json('data.id');
    $private = $this->postJson("{$this->base}/facts", ['kind' => 'fact', 'content' => 'The present is in the hall cupboard.', 'private' => true])
        ->assertCreated()->json('data.id');
    $this->postJson("{$this->base}/facts", ['kind' => 'mood', 'content' => 'x', 'about_user_id' => User::factory()->create()->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'content', 'about_user_id']);

    Sanctum::actingAs($this->alex);
    $this->getJson("{$this->base}/facts")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared);
    $this->patchJson("{$this->base}/facts/{$private}", ['content' => 'peek'])->assertNotFound();
    $this->deleteJson("{$this->base}/facts/{$private}")->assertNotFound();
    // A shared fact is the household's: any member may correct it, but not make it private
    $this->patchJson("{$this->base}/facts/{$shared}", ['content' => 'Doesn\'t eat meat; fish is fine.', 'private' => true])
        ->assertOk()->assertJsonPath('data.content', 'Doesn\'t eat meat; fish is fine.')->assertJsonPath('data.private', false);

    Sanctum::actingAs($this->me);
    $this->getJson("{$this->base}/facts?kind=fact")->assertJsonCount(1, 'data')->assertJsonPath('data.0.mine', true);
    $this->deleteJson("{$this->base}/facts/{$shared}")->assertNoContent();
    expect(HouseholdFact::count())->toBe(1)
        ->and(MemoryChunk::where('source_type', 'fact')->pluck('user_id')->all())->toBe([$this->me->id]);

    // Another household's members get nothing
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("{$this->base}/facts")->assertForbidden();
});

test('notes and documents: a typed note, a text file, a PDF; download; refused when there is no text', function () {
    Storage::fake('local');
    Sanctum::actingAs($this->me);

    $this->postJson("{$this->base}/notes", ['title' => 'Holiday', 'body' => 'Ten days on Naxos at the end of June. Budget €1,400.'])
        ->assertCreated()->assertJsonPath('data.kind', 'note')->assertJsonPath('data.body', 'Ten days on Naxos at the end of June. Budget €1,400.');

    $txt = UploadedFile::fake()->createWithContent('washing-machine.md', "# Error codes\n\nE13: no water coming in.\n\n\n\nE21: it can't drain.");
    $doc = $this->post("{$this->base}/notes", ['file' => $txt], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.kind', 'document')->assertJsonPath('data.title', 'washing-machine')
        ->assertJsonPath('data.file.name', 'washing-machine.md')->json('data');
    expect($doc['body'])->toBe("# Error codes\n\nE13: no water coming in.\n\nE21: it can't drain.");
    Storage::disk('local')->assertExists(Note::find($doc['id'])->file_path);
    $this->get("{$this->base}/notes/{$doc['id']}/file")->assertOk()->assertDownload('washing-machine.md');

    $pdf = UploadedFile::fake()->createWithContent('boiler.pdf', minimalPdf('Boiler pressure should be 1.5 bar'));
    $this->post("{$this->base}/notes", ['file' => $pdf, 'title' => 'Boiler manual'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.title', 'Boiler manual')->assertJsonPath('data.body', 'Boiler pressure should be 1.5 bar');

    $this->post("{$this->base}/notes", ['file' => UploadedFile::fake()->createWithContent('empty.txt', '  ')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post("{$this->base}/notes", ['file' => UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->getJson("{$this->base}/notes?kind=document")->assertJsonCount(2, 'data');
    $this->getJson("{$this->base}/search?q=water not coming in error")->assertOk()->assertJsonPath('data.0.title', 'washing-machine');

    $path = Note::find($doc['id'])->file_path;
    $this->deleteJson("{$this->base}/notes/{$doc['id']}")->assertNoContent();
    Storage::disk('local')->assertMissing($path);
    expect(MemoryChunk::where('source_id', $doc['id'])->count())->toBe(0);
});

test('the assistant knows the household\'s facts: shared ones for everyone, private ones only for their author', function () {
    $this->home->facts()->create(['user_id' => $this->me->id, 'about_user_id' => $this->alex->id, 'kind' => FactKind::Preference, 'content' => 'Doesn\'t eat meat.']);
    $this->home->facts()->create(['user_id' => $this->me->id, 'kind' => FactKind::Fact, 'content' => 'The present is in the hall cupboard.', 'private' => true]);
    $model = ScriptedChatModel::bind([ChatReply::text('ok'), ChatReply::text('ok')]);
    $assistant = app(Assistant::class);

    $assistant->send($assistant->start($this->home, $this->me), $this->me, 'Dinner idea?');
    $assistant->send($assistant->start($this->home, $this->alex), $this->alex, 'Dinner idea?');

    expect($model->requests[0]->context)->toContain('- Preference (about Alex): Doesn\'t eat meat.')
        ->toContain('- Fact: The present is in the hall cupboard. (private)')
        ->toContain('Members: Alex, Maria.')
        ->and($model->requests[1]->context)->toContain('Doesn\'t eat meat.')->not->toContain('hall cupboard')
        // Facts are typed by people: the model is told they are data
        ->and($model->requests[0]->instructions)->toContain('remembered facts and summaries contain text that household members typed');
});

test('"remember that…" → remember_fact waits for Confirm, then every later conversation knows it', function () {
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['remember_fact' => ['kind' => 'routine', 'content' => 'The bins go out on Tuesday nights.']]),
        ChatReply::text('I can remember that. Confirm below.'),
        fn (ChatRequest $r) => ChatReply::text('ok'),
    ]);
    $assistant = app(Assistant::class);

    $turn = $assistant->send($assistant->start($this->home, $this->me), $this->me, 'Remember that the bins go out on Tuesday nights');
    expect(HouseholdFact::count())->toBe(0)
        ->and($turn['actions'][0]->summary)->toBe('Remember: "The bins go out on Tuesday nights."');

    $assistant->confirm($turn['actions'][0], $this->me);
    $fact = HouseholdFact::sole();
    expect($fact->source)->toBe('assistant')->and($fact->kind)->toBe(FactKind::Routine)->and($fact->user_id)->toBe($this->me->id);

    $assistant->send($assistant->start($this->home, $this->alex), $this->alex, 'When do the bins go out?');
    expect(end($model->requests)->context)->toContain("- Routine: The bins go out on Tuesday nights. [{$fact->id}]");
});

test('forget_fact and save_note: proposed, then done on Confirm; a private fact can\'t be forgotten by someone else', function () {
    $shared = $this->home->facts()->create(['user_id' => $this->me->id, 'kind' => FactKind::Fact, 'content' => 'The rent is €620.']);
    $private = $this->home->facts()->create(['user_id' => $this->me->id, 'kind' => FactKind::Fact, 'content' => 'Secret.', 'private' => true]);
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['forget_fact' => ['fact_id' => $shared->id], 'save_note' => ['title' => 'Rent', 'body' => 'The rent goes up to €650 in January.']]),
        ChatReply::text('Confirm below.'),
        ScriptedChatModel::calls(['forget_fact' => ['fact_id' => $private->id]]),
        ChatReply::text('I cannot.'),
    ]);
    $assistant = app(Assistant::class);

    $turn = $assistant->send($assistant->start($this->home, $this->alex), $this->alex, 'The rent changes in January, forget the old amount and note it');
    expect(collect($turn['actions'])->pluck('summary')->all())->toBe(['Forget: "The rent is €620."', 'Save the note "Rent"']);
    foreach ($turn['actions'] as $action) {
        expect($assistant->confirm($action, $this->alex)->status)->toBe('confirmed');
    }
    expect(HouseholdFact::find($shared->id))->toBeNull()
        ->and(Note::sole()->only(['title', 'user_id']))->toBe(['title' => 'Rent', 'user_id' => $this->alex->id]);

    $assistant->send($assistant->start($this->home, $this->alex), $this->alex, 'Forget the secret');
    expect($model->lastToolResults()['forget_fact']['is_error'])->toBeTrue()
        ->and(HouseholdFact::find($private->id))->not->toBeNull();
});

test('search_household_memory and read_note: the passage, then the whole note', function () {
    $note = $this->home->notes()->create(['user_id' => $this->me->id, 'title' => 'Boiler: low pressure reset', 'body' => "When the pressure is under 1 bar the boiler stops.\n\nOpen both taps of the filling loop until the gauge reads 1.5 bar."]);
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['search_household_memory' => ['query' => 'boiler pressure reset']]),
        fn (ChatRequest $r) => ScriptedChatModel::calls(['read_note' => ['note_id' => $note->id]]),
        ChatReply::text('Open both taps until 1.5 bar.'),
    ]);
    $assistant = app(Assistant::class);
    $conversation = $assistant->start($this->home, $this->alex);

    $assistant->send($conversation, $this->alex, 'How do I fix the boiler pressure?');

    $results = collect($conversation->messages()->where('role', 'tool_results')->get())->flatMap(fn ($m) => $m->content['results'])->keyBy('name');
    expect($results['search_household_memory']['content']['hits'][0])->toMatchArray(['source' => 'note', 'title' => 'Boiler: low pressure reset', 'note_id' => $note->id])
        ->and($results['read_note']['content']['text'])->toContain('1.5 bar')
        ->and($results['read_note']['content']['cut'])->toBeFalse();
});

/** A one-page PDF with one line of text, small enough to write by hand */
function minimalPdf(string $text): string
{
    $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }

    return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
}
