<?php

namespace App\Services\Methodology;

use App\Models\Document;
use App\Models\ProgrammeRow;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * "Copy as link" shortcuts. A programme step is pasted as [[step:<uuid>]] and a document as
 * [[doc:<uuid>]]. Tokens are resolved at display time within the current firm (RLS + tenant
 * scope), so a shortcut always shows the live reference and title, and a token copied from
 * another firm simply shows as a broken link.
 */
class LinkTokens
{
    public const PATTERN = '/\[\[(step|doc):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]\]/i';

    /** @return list<array{type: string, uuid: string}> */
    public function find(?string ...$texts): array
    {
        $out = [];
        foreach ($texts as $t) {
            if ($t && preg_match_all(self::PATTERN, $t, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $out[strtolower($hit[1]).':'.strtolower($hit[2])] = ['type' => strtolower($hit[1]), 'uuid' => strtolower($hit[2])];
                }
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<array{type: string, uuid: string}>  $tokens
     * @return Collection<string, array{type: string, label: string, title: string, url: ?string, ok: bool}>
     */
    public function resolve(array $tokens): Collection
    {
        $tokens = collect($tokens);
        $steps = ProgrammeRow::with('sheet.engagement')->whereIn('uuid', $tokens->where('type', 'step')->pluck('uuid'))->get()->keyBy('uuid');
        $docs = Document::with('engagement')->whereIn('uuid', $tokens->where('type', 'doc')->pluck('uuid'))->get()->keyBy('uuid');

        return $tokens->mapWithKeys(function ($t) use ($steps, $docs) {
            $key = $t['type'].':'.$t['uuid'];
            if ($t['type'] === 'step' && ($r = $steps->get($t['uuid']))) {
                return [$key => ['type' => 'step', 'ok' => true, 'label' => $r->ref, 'title' => \Illuminate\Support\Str::limit($r->procedure, 90),
                    'url' => route('programmes.show', [$r->sheet->engagement, $r->sheet]).'#row-'.$r->uuid]];
            }
            if ($t['type'] === 'doc' && ($d = $docs->get($t['uuid']))) {
                return [$key => ['type' => 'doc', 'ok' => true, 'label' => $d->title, 'title' => 'Document', 'url' => route('documents.show', [$d->engagement, $d])]];
            }

            return [$key => ['type' => $t['type'], 'ok' => false, 'label' => 'Broken link', 'title' => 'The linked item no longer exists or is outside this firm.', 'url' => null]];
        });
    }

    /** Escapes the text and turns tokens into shortcut chips. Line breaks are kept by the caller's CSS. */
    public function render(?string $text, ?Collection $resolved = null): HtmlString
    {
        $text ??= '';
        $resolved ??= $this->resolve($this->find($text));
        $html = '';
        $offset = 0;
        preg_match_all(self::PATTERN, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            [$whole, $pos] = $hit[0];
            $html .= e(substr($text, $offset, $pos - $offset));
            $html .= $this->chip($resolved->get(strtolower($hit[1][0]).':'.strtolower($hit[2][0])));
            $offset = $pos + strlen($whole);
        }
        $html .= e(substr($text, $offset));

        return new HtmlString($html);
    }

    public function chip(?array $link): string
    {
        if (! $link || ! $link['ok']) {
            return '<span class="link-chip link-chip-broken" title="'.e($link['title'] ?? 'Broken link').'">⚠ broken link</span>';
        }
        $icon = $link['type'] === 'step' ? '↳' : '📎';

        return '<a href="'.e($link['url']).'" class="link-chip" title="'.e($link['title']).'">'.$icon.' '.e($link['label']).'</a>';
    }
}
