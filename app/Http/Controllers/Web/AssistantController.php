<?php

namespace App\Http\Controllers\Web;

use App\Ai\AiUnavailable;
use App\Ai\Assistant;
use App\Ai\AssistantRefused;
use App\Ai\Presenter;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chat page (resources/js/pages/assistant.tsx). The page posts with
 * Inertia and gets the conversation back as props, so the browser uses the
 * same session login and CSRF protection as every other page; the phone uses
 * the same Assistant through the API.
 */
class AssistantController extends WebController
{
    public function __construct(
        private Assistant $assistant,
        private Presenter $present,
    ) {}

    public function show(Request $request, ?string $conversation = null): Response
    {
        $current = $conversation ? $this->find($request, $conversation) : null;

        return Inertia::render('assistant', [
            'conversations' => $this->mine($request)->orderByDesc('updated_at')->limit(30)->get()
                ->map($this->present->conversation(...))->all(),
            'conversation' => $current ? $this->present->conversation($current) : null,
            'messages' => $current ? $this->present->messages($current->messages) : [],
            'actions' => $current ? $this->present->actions($current->pendingActions()->orderBy('created_at')->get()) : [],
            'provider' => config('homehelper.ai.provider'),
            'suggestions' => [
                'What should I do today?',
                'What do I need to buy?',
                'How much did I spend this month?',
                'What tasks are overdue?',
                'Which plants need attention?',
            ],
        ]);
    }

    /** A new conversation with its first message */
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);
        $conversation = $this->assistant->start($this->household($request), $request->user());
        $this->send($request, $conversation, $data['message']);

        return to_route('assistant.show', $conversation->id);
    }

    public function message(Request $request, string $conversation): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);
        $conversation = $this->find($request, $conversation);
        $this->send($request, $conversation, $data['message']);

        return to_route('assistant.show', $conversation->id);
    }

    public function confirm(Request $request, string $action): RedirectResponse
    {
        $action = $this->assistant->confirm($this->findAction($request, $action), $request->user());
        Inertia::flash('toast', $action->status === 'confirmed'
            ? ['type' => 'success', 'message' => 'Done: '.$action->summary.'.']
            : ['type' => 'error', 'message' => $action->status === 'expired' ? 'That suggestion expired. Ask again.' : 'That could not be done: '.($action->result['error'] ?? 'unknown error')]);

        return to_route('assistant.show', $action->conversation_id);
    }

    public function decline(Request $request, string $action): RedirectResponse
    {
        $action = $this->assistant->decline($this->findAction($request, $action));

        return to_route('assistant.show', $action->conversation_id);
    }

    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        $this->find($request, $conversation)->delete();

        return to_route('assistant');
    }

    private function send(Request $request, AiConversation $conversation, string $message): void
    {
        try {
            $this->assistant->send($conversation, $request->user(), $message);
        } catch (AssistantRefused|AiUnavailable $e) {
            if ($e instanceof AiUnavailable) {
                report($e);
            }
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /** @return Builder<AiConversation> */
    private function mine(Request $request)
    {
        return AiConversation::query()
            ->where('household_id', $this->household($request)->id)
            ->where('user_id', $request->user()->id);
    }

    private function find(Request $request, string $id): AiConversation
    {
        return $this->mine($request)->findOrFail($id);
    }

    private function findAction(Request $request, string $id): AiPendingAction
    {
        return AiPendingAction::query()->where('user_id', $request->user()->id)
            ->whereHas('conversation', fn ($q) => $q->where('household_id', $this->household($request)->id))
            ->findOrFail($id);
    }
}
