<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('audira:library-embed', function (\App\Ai\Rag\LibraryRetriever $retriever) {
    if (! $retriever->vectorEnabled()) {
        $this->error('Vector search is not enabled: set AI_RAG_DRIVER=hybrid, configure the embedding model and install pgvector.');

        return 1;
    }
    $ids = \App\Models\LibraryVersion::where('status', 'published')->pluck('id');
    foreach ($ids as $id) {
        \App\Jobs\EmbedLibraryVersion::dispatchSync($id);
    }
    $this->info("Embedded {$ids->count()} published library version(s).");

    return 0;
})->purpose('Create or refresh vector embeddings for published Audit Library paragraphs');

Artisan::command('audira:monitor', function (\App\Services\Monitoring\EngagementMonitor $monitor) {
    $r = $monitor->runAll();
    $this->info("Scanned {$r['engagements']} engagement(s): {$r['opened']} new alert(s), {$r['resolved']} resolved.");

    return 0;
})->purpose('Evaluate engagement health rules and notify partners and managers of new alerts');

Artisan::command('audira:billing-run', function (\App\Services\Billing\InvoiceService $invoices) {
    $r = $invoices->run();
    $this->info("Issued {$r['issued']} invoice(s); {$r['past_due']} subscription(s) moved to past due.");

    return 0;
})->purpose('Issue invoices for ended subscription periods and flag overdue subscriptions');

\Illuminate\Support\Facades\Schedule::command('audira:monitor')->dailyAt('07:00')->timezone(config('audira.display_timezone'))->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('audira:billing-run')->dailyAt('02:00')->timezone(config('audira.display_timezone'))->withoutOverlapping();
