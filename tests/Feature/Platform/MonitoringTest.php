<?php

namespace Tests\Feature\Platform;

use App\Models\MonitoringAlert;
use App\Models\PbcItem;
use App\Notifications\MonitoringAlertsNotification;
use App\Services\Monitoring\EngagementMonitor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Ai\AiTestCase;

class MonitoringTest extends AiTestCase
{
    private function alerts(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => MonitoringAlert::whereNull('resolved_at')->orderBy('kind')->pluck('severity', 'kind')->all());
    }

    public function test_daily_rules_open_update_and_resolve_alerts_and_notify_once(): void
    {
        Notification::fake();
        $e = $this->ctx['engagement'];
        $e->forceFill(['reporting_deadline' => now()->addDays(5)->toDateString(), 'status' => 'fieldwork'])->save();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/client-requests', ['title' => 'Bank statements', 'description' => 'June statements', 'category' => 'banking', 'due_on' => now()->addDay()->toDateString()])->assertSessionHasNoErrors();
        $item = PbcItem::sole();
        $item->forceFill(['status' => 'requested', 'due_on' => now()->subDays(3)->toDateString()])->save();

        $this->artisan('audira:monitor')->assertSuccessful();
        $alerts = $this->alerts();
        $this->assertSame('warn', $alerts['deadline']);
        $this->assertSame('warn', $alerts['no_materiality']);
        $this->assertSame('warn', $alerts['overdue_requests']);
        Notification::assertSentTo([$this->ctx['partner'], $this->ctx['manager']], MonitoringAlertsNotification::class);
        Notification::assertNotSentTo($this->ctx['staff'], MonitoringAlertsNotification::class);

        // Second run: same alerts, no new notifications; deadline escalates when passed.
        Notification::fake();
        $e->forceFill(['reporting_deadline' => now()->subDay()->toDateString()])->save();
        app(EngagementMonitor::class)->runAll();
        $this->assertSame('danger', $this->alerts()['deadline']);
        Notification::assertNothingSent();

        // Fixing the cause resolves the alert.
        $e->forceFill(['reporting_deadline' => now()->addMonths(2)->toDateString()])->save();
        $item->forceFill(['status' => 'accepted'])->save();
        app(EngagementMonitor::class)->runAll();
        $this->assertArrayNotHasKey('deadline', $this->alerts());
        $this->assertArrayNotHasKey('overdue_requests', $this->alerts());

        $this->actingAs($this->ctx['manager'])->get($this->base())->assertOk()->assertSee('Engagement health')->assertSee('without approved materiality');
    }

    public function test_monitoring_agent_digest_is_advisory_and_guarded(): void
    {
        $this->ctx['engagement']->forceFill(['reporting_deadline' => now()->addDays(3)->toDateString()])->save();
        app(EngagementMonitor::class)->runAll();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['engagement_health']]))
            ->push($this->final(['summary' => 'Deadline in three days.', 'priorities' => [
                ['action' => 'Agree a revised timetable with the client.', 'alert_kind' => 'deadline'],
                ['action' => 'Issue the unmodified opinion now.', 'alert_kind' => null],
                ['action' => 'Chase the bank.', 'alert_kind' => 'made_up'],
            ]]))]);
        $this->actingAs($this->ctx['manager'])->post($this->base().'/ai-runs', ['agent' => 'monitoring', 'task' => 'health_digest'])->assertSessionHasNoErrors();
        $run = $this->ctx['engagement']->aiRuns()->where('agent', 'monitoring')->sole();
        $this->assertSame(['deadline', null], array_column($run->output['priorities'], 'alert_kind'));
        $this->assertCount(2, $run->output['violations']);
        $this->actingAs($this->ctx['manager'])->get($this->base())->assertOk()->assertSee('Health digest')->assertSee('Agree a revised timetable');
    }
}
