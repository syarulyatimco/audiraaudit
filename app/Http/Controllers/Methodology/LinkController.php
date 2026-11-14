<?php

namespace App\Http\Controllers\Methodology;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ProgrammeRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** Resolves a copied shortcut (/links/step/<uuid>, /links/doc/<uuid>) to the page it points at. */
class LinkController extends Controller
{
    public function show(string $type, string $uuid): RedirectResponse
    {
        if ($type === 'step') {
            $row = ProgrammeRow::with('sheet.engagement')->where('uuid', $uuid)->firstOrFail();
            Gate::authorize('view', $row->sheet->engagement);

            return redirect()->to(route('programmes.show', [$row->sheet->engagement, $row->sheet]).'#row-'.$row->uuid);
        }
        $doc = Document::with('engagement')->where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $doc->engagement);

        return redirect()->route('documents.show', [$doc->engagement, $doc]);
    }
}
