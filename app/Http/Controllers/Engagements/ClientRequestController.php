<?php

namespace App\Http\Controllers\Engagements;

use App\Enums\PbcCategory;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\EngagementMessage;
use App\Models\PbcItem;
use App\Services\Portal\MessageService;
use App\Services\Portal\PbcService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Firm side of PBC requests and client correspondence. */
class ClientRequestController extends Controller
{
    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $u = $request->user();
        $locked = $engagement->status->isLocked();
        $orchestrator = app(\App\Ai\Runtime\AiOrchestrator::class);

        return view('engagements.client-requests', [
            'engagement' => $engagement->load('client'),
            'items' => $engagement->pbcItems()->with(['contact', 'procedure', 'documents.currentVersion'])->get(),
            'messages' => $engagement->messages()->with(['author', 'sender'])->get(),
            'contacts' => $engagement->client->portalUsers()->where('status', 'active')->get(),
            'procedures' => $engagement->procedures()->active()->get(),
            'canManage' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.manage_pbc'),
            'canMessage' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.message_client'),
            'aiRun' => $engagement->aiRuns()->where('agent', 'client_communication')->first(),
            'aiUnavailable' => $orchestrator->unavailableReasons($engagement, 'client_communication'),
            'docRuns' => $engagement->aiRuns()->where('agent', 'document_intelligence')->get()->keyBy(fn ($r) => $r->input['subject'] ?? ''),
            'docUnavailable' => $orchestrator->unavailableReasons($engagement, 'document_intelligence'),
        ]);
    }

    public function store(Request $request, Engagement $engagement, PbcService $pbc): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $pbc->create($engagement, $this->validated($request), $request->user());

        return back()->with('status', 'Request added as a draft. Send it when ready.');
    }

    public function send(Request $request, Engagement $engagement, PbcService $pbc): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['items' => ['required', 'array'], 'items.*' => ['integer']]);
        $n = $pbc->request($engagement, $data['items'], $request->user());

        return back()->with('status', "{$n} request(s) sent to the client portal.");
    }

    public function review(Request $request, Engagement $engagement, PbcItem $item, PbcService $pbc): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'return', 'cancel'])], 'note' => ['nullable', 'string', 'max:5000']]);
        match ($data['decision']) {
            'accept' => $pbc->accept($item, $request->user()),
            'return' => filled($data['note'] ?? null) ? $pbc->returnToClient($item, $data['note'], $request->user())
                : throw \Illuminate\Validation\ValidationException::withMessages(['note' => 'Tell the client what is missing.']),
            'cancel' => $pbc->cancel($item, $request->user()),
        };

        return back()->with('status', 'Request updated.');
    }

    public function draftMessage(Request $request, Engagement $engagement, MessageService $messages): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:10000']]);
        $messages->draft($engagement, $data['subject'], $data['body'], $request->user());

        return back()->with('status', 'Draft saved. Review it and send when ready.');
    }

    public function updateMessage(Request $request, Engagement $engagement, EngagementMessage $message, MessageService $messages): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($message->engagement_id === $engagement->id, 404);
        $data = $request->validate(['subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:10000'], 'action' => ['required', Rule::in(['save', 'send', 'discard'])]]);
        if ($data['action'] === 'discard') {
            $messages->discard($message, $request->user());

            return back()->with('status', 'Draft discarded.');
        }
        $messages->updateDraft($message, $data['subject'], $data['body'], $request->user());
        if ($data['action'] === 'send') {
            $messages->send($message->fresh(), $request->user());

            return back()->with('status', 'Message sent to the client portal.');
        }

        return back()->with('status', 'Draft updated.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', Rule::enum(PbcCategory::class)], 'procedure_id' => ['nullable', 'integer'],
            'assigned_contact_id' => ['nullable', 'integer'], 'due_on' => ['nullable', 'date'],
        ]);
    }
}
