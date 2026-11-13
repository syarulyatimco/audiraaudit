<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\PbcItem;
use App\Services\Portal\MessageService;
use App\Services\Portal\PbcService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Client portal: a client contact sees only their client's engagements, requests and messages. */
class PortalController extends Controller
{
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $engagements = Engagement::where('client_id', $user->client_id)->whereNotIn('status', ['archived'])->orderByDesc('period_end')->get();
        $items = PbcItem::whereIn('engagement_id', $engagements->pluck('id'))->visibleToClient()->with('engagement')->orderBy('due_on')->get();

        return view('portal.dashboard', ['client' => $user->client, 'engagements' => $engagements, 'items' => $items]);
    }

    public function engagement(Request $request, Engagement $engagement): View
    {
        $this->assertOwn($request, $engagement);
        $engagement->messages()->where('direction', 'outbound')->where('status', 'sent')->whereNull('read_at')->update(['read_at' => now()]);

        return view('portal.engagement', [
            'engagement' => $engagement->load('client'),
            'items' => $engagement->pbcItems()->visibleToClient()->with(['documents' => fn ($q) => $q->with('currentVersion')])->get(),
            'messages' => $engagement->messages()->where('status', 'sent')->with(['author', 'sender'])->get(),
        ]);
    }

    public function upload(Request $request, Engagement $engagement, PbcItem $item, PbcService $pbc): RedirectResponse
    {
        $this->assertOwn($request, $engagement);
        $ext = config('audira.documents.extensions');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('audira.documents.max_kb'), 'extensions:'.implode(',', $ext), new \App\Rules\ContentMatchesExtension],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $pbc->clientUpload($item, $request->file('file'), $data['note'] ?? null, $request->user());

        return back()->with('status', 'Thank you — your file was received and will be reviewed by the audit team.');
    }

    public function reply(Request $request, Engagement $engagement, MessageService $messages): RedirectResponse
    {
        $this->assertOwn($request, $engagement);
        $data = $request->validate(['subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:10000']]);
        $messages->clientReply($engagement, $data['subject'], $data['body'], $request->user());

        return back()->with('status', 'Message sent to your audit team.');
    }

    private function assertOwn(Request $request, Engagement $engagement): void
    {
        abort_unless($engagement->client_id === $request->user()->client_id && $engagement->status->value !== 'archived', 404);
    }
}
