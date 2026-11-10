<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiUnavailable;
use App\Ai\Assistant;
use App\Ai\AssistantRefused;
use App\Ai\Presenter;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Household;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The assistant over the API (docs/assistant.md#api). Conversations are
 * private: another member's conversation is 404, as if it didn't exist.
 */
class AssistantController extends Controller
{
    public function __construct(
        private Assistant $assistant,
        private Presenter $present,
    ) {}

    /**
     * Your conversations in this household, newest first.
     */
    public function index(Request $request, Household $household): JsonResponse
    {
        $conversations = AiConversation::query()
            ->where('household_id', $household->id)->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')->limit(50)->get();

        return response()->json(['data' => $conversations->map($this->present->conversation(...))->all()]);
    }

    /**
     * Start a conversation, optionally with its first message.
     */
    public function store(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate(['message' => ['sometimes', 'string', 'min:1', 'max:4000']]);
        $conversation = $this->assistant->start($household, $request->user());

        if (! isset($data['message'])) {
            return response()->json(['conversation' => $this->present->conversation($conversation), 'messages' => [], 'actions' => []], 201);
        }

        return $this->turn($request, $conversation, $data['message'], 201);
    }

    /**
     * One conversation: its messages and its actions.
     */
    public function show(Request $request, Household $household, string $conversation): JsonResponse
    {
        $conversation = $this->find($request, $household, $conversation);

        return response()->json([
            'conversation' => $this->present->conversation($conversation),
            'messages' => $this->present->messages($conversation->messages),
            'actions' => $this->present->actions($conversation->pendingActions()->orderBy('created_at')->get()),
        ]);
    }

    /**
     * Ask the assistant. Answers when the whole turn is done: the new messages,
     * and any changes it proposes for you to confirm.
     */
    public function message(Request $request, Household $household, string $conversation): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:1', 'max:4000']]);

        return $this->turn($request, $this->find($request, $household, $conversation), $data['message']);
    }

    public function destroy(Request $request, Household $household, string $conversation): Response
    {
        $this->find($request, $household, $conversation)->delete();

        return response()->noContent();
    }

    /**
     * Do what the assistant proposed. Checked again: membership, permission, input.
     */
    public function confirm(Request $request, Household $household, string $action): JsonResponse
    {
        $action = $this->findAction($request, $household, $action);
        if ($action->status !== 'pending') {
            return $this->error($request, 409, 'action_closed', "This was already {$action->status}.");
        }

        $action = $this->assistant->confirm($action, $request->user());

        return response()->json(['action' => $this->present->action($action)], $action->status === 'confirmed' ? 200 : 422);
    }

    public function decline(Request $request, Household $household, string $action): JsonResponse
    {
        $action = $this->assistant->decline($this->findAction($request, $household, $action));

        return response()->json(['action' => $this->present->action($action)]);
    }

    /**
     * What the assistant can do here: each tool, and whether it changes data.
     */
    public function tools(ToolRegistry $tools): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn (Tool $t) => [
            'name' => $t->name(),
            'label' => $t->label(),
            'description' => $t->description(),
            'changes_data' => $t->writes(),
            'needs_confirmation' => $t->writes(),
            'enabled' => ! $t->writes() || (bool) config('homehelper.ai.allow_writes'),
        ], $tools->all()))]);
    }

    private function turn(Request $request, AiConversation $conversation, string $message, int $status = 200): JsonResponse
    {
        try {
            $turn = $this->assistant->send($conversation, $request->user(), $message);
        } catch (AssistantRefused $e) {
            return $this->error($request, $e->status, $e->errorCode, $e->getMessage());
        } catch (AiUnavailable $e) {
            report($e);

            return $this->error($request, 503, 'ai_unavailable', $e->getMessage(), [
                'retryable' => $e->retryable,
                'conversation' => $this->present->conversation($conversation),
            ]);
        }

        return response()->json([
            'conversation' => $this->present->conversation($conversation->refresh()),
            'messages' => $this->present->messages($turn['messages']),
            'actions' => $this->present->actions($turn['actions']),
        ], $status);
    }

    /**
     * The API's one error shape (docs/api.md#errors), for errors that aren't exceptions
     *
     * @param  array<string, mixed>  $extra
     */
    private function error(Request $request, int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        $id = (string) $request->attributes->get('request_id');

        return response()->json(['message' => $message, 'code' => $code] + $extra + ['request_id' => $id], $status)
            ->header('X-Request-Id', $id);
    }

    private function find(Request $request, Household $household, string $id): AiConversation
    {
        return AiConversation::query()
            ->where('household_id', $household->id)
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
    }

    private function findAction(Request $request, Household $household, string $id): AiPendingAction
    {
        return AiPendingAction::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('conversation', fn ($q) => $q->where('household_id', $household->id))
            ->findOrFail($id);
    }
}
