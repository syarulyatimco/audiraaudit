<?php

namespace App\Http\Controllers\Firm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('activity.view');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:80'],
        ]);

        $logs = ActivityLog::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('description', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('actor_label', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', 'like', addcslashes($event, '%_\\').'%'))
            ->latest('created_at')->latest('id')
            ->paginate(25)->withQueryString();

        $events = ['auth', 'user', 'firm', 'client', 'engagement', 'approval', 'subscription'];

        return view('firm.activity', compact('logs', 'filters', 'events'));
    }
}
