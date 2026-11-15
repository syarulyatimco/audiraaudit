@php $svc = app(\App\Services\ClientFs\ClientFsService::class); $st = $content['statements']; @endphp
@extends('pdf.layout', ['title' => 'Financial statements — '.$engagement->client->name])
@section('content')
    <div class="meta">
        <h1>{{ $engagement->client->name }}</h1>
        <div class="muted">Financial statements for {{ $engagement->periodLabel() }}</div>
        <div class="small muted">Prepared by management.
            @if ($version)
                Version {{ $version->version }} submitted {{ local_time($version->submitted_at, 'j M Y') }}@if($version->directors_approved) · approved by the directors ({{ $version->approved_by_names }}) on {{ $version->approved_on->format('j M Y') }}@endif.
            @else
                Working draft, {{ local_time(now(), 'j M Y') }}.
            @endif
        </div>
        @if (! $version || ! $version->directors_approved)<p><span class="stamp">DRAFT</span></p>@endif
    </div>
    @foreach (\App\Models\ClientFinancialStatement::STATEMENTS as $i => $key)
        @php $rows = $st[$key]['rows'] ?? []; @endphp
        <h2 @if($i > 0) class="page-break" @endif>{{ $st[$key]['label'] ?? $key }}</h2>
        <table>
            <thead><tr><th></th><th class="note">Note</th><th class="num">Current year</th><th class="num">Prior year</th></tr></thead>
            <tbody>
            @foreach ($key === 'sofp' ? ['asset' => 'Total assets', 'el' => 'Total equity and liabilities'] : [null => null] as $side => $totalLabel)
                @foreach ($rows as $r)
                    @continue($key === 'sofp' && $r['side'] !== $side)
                    <tr class="{{ $r['kind'] }}"><td>{{ $r['caption'] }}</td><td class="note">{{ $r['note'] }}</td>
                        <td class="num">{{ $r['kind'] === 'heading' ? '' : amount($r['cy']) }}</td><td class="num">{{ $r['kind'] === 'heading' ? '' : amount($r['py']) }}</td></tr>
                @endforeach
                @if ($key === 'sofp')
                    @php [$cy, $py] = $svc->totals($rows, $side); @endphp
                    <tr class="total"><td>{{ $totalLabel }}</td><td></td><td class="num">{{ amount($cy, false) }}</td><td class="num">{{ amount($py, false) }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
    @endforeach
    @if (! empty($content['notes']))
        <h2 class="page-break">Notes to the financial statements</h2>
        @foreach ($content['notes'] as $n => $note)
            <h3>{{ $n + 1 }}. {{ $note['title'] }}</h3>
            <div class="body">{{ $note['body'] ?: '—' }}</div>
        @endforeach
    @endif
@endsection
