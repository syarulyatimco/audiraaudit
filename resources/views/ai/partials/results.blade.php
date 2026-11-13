{{-- Task-specific result view for a usable run: $run, $engagement --}}
@switch($run->task)
    @case('suggest_mappings') @include('ai.partials.mapping-results') @break
    @case('review_tb') @include('ai.partials.review-results') @break
    @case('recommend_materiality') @include('ai.partials.materiality-results') @break
    @case('suggest_risks') @include('ai.partials.risk-results') @break
    @case('draft_procedures') @include('ai.partials.procedure-results') @break
    @case('recommend_parameters') @include('ai.partials.sampling-results') @break
    @case('draft_request_message') @include('ai.partials.message-results') @break
    @case('classify_document') @include('ai.partials.extraction-results') @break
    @case('draft_test_results') @include('ai.partials.test-results') @break
    @case('draft_findings') @include('ai.partials.finding-results') @break
    @case('draft_checklist') @include('ai.partials.checklist-results') @break
    @case('pre_review') @include('ai.partials.review-note-results') @break
    @case('health_digest') @include('ai.partials.digest-results') @break
@endswitch
