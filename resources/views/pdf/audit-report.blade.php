@extends('pdf.layout', ['title' => 'Independent auditors\' report — '.$engagement->client->name])
@section('content')
    <div class="meta">
        <div class="small muted">{{ $engagement->firm->name }}@if($engagement->firm->audit_firm_no) · {{ $engagement->firm->audit_firm_no }}@endif</div>
        <h1>{{ $engagement->client->name }}</h1>
        <div class="small muted">{{ $engagement->name }} · {{ $engagement->periodLabel() }}</div>
        @unless ($report->isSigned())<p><span class="stamp">DRAFT — NOT SIGNED</span></p>@endunless
    </div>
    <div class="body">{{ $report->body }}</div>
    <div class="footer small muted">
        @if ($report->isSigned())
            Signed by {{ $report->signer?->name }} on {{ local_time($report->signed_at, 'j F Y') }} · report dated {{ $report->report_date?->format('j F Y') }} · SHA-256 {{ $report->body_sha256 }}
        @else
            Draft generated {{ local_time(now()) }}. This draft has not been approved or signed.
        @endif
    </div>
@endsection
