<?php

namespace Tests\Feature\Ai;

use App\Models\CompletionItem;
use App\Models\ReviewNote;
use Illuminate\Support\Facades\Http;

class CompletionAgentsTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
        $this->actingAs($this->ctx['senior'])->get($this->base().'/completion')->assertOk(); // creates the checklist
    }

    public function test_completion_agent_drafts_responses_without_opinions_or_conclusions(): void
    {
        $done = CompletionItem::where('key', 'independence')->sole();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/completion/items/{$done->uuid}", ['status' => 'done', 'response' => 'Confirmations on file.'])->assertSessionHasNoErrors();

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['completion_checklist'], ['findings_list']]))
            ->push($this->final(['summary' => 'Drafted.', 'responses' => [
                ['key' => 'litigation', 'response' => "No findings relate to litigation.\nTo be confirmed by the engagement team: lawyers' confirmations."],
                ['key' => 'uncorrected_misstatements', 'response' => 'There are no uncorrected misstatements, so they are immaterial. Total RM 98,765.00 reviewed.'],
                ['key' => 'going_concern', 'response' => 'Nothing indicates doubt; an unmodified opinion is appropriate.'],
                ['key' => 'independence', 'response' => 'Already answered.'],
                ['key' => 'made_up', 'response' => 'x'],
            ]]))]);
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'completion', 'task' => 'draft_checklist'])->assertSessionHasNoErrors();
        $run = $this->ctx['engagement']->aiRuns()->where('agent', 'completion')->sole();
        $this->assertSame('needs_review', $run->status);
        $this->assertSame(['litigation', 'uncorrected_misstatements'], array_column($run->output['responses'], 'key'));
        $v = implode(' ', $run->output['violations']);
        $this->assertStringContainsString('refers to the audit opinion', $v);
        $this->assertStringContainsString('materiality or going-concern conclusion', $v);
        $this->assertStringContainsString('98765', $v);
        $this->assertStringContainsString('not an open checklist item', $v);

        $this->actingAs($this->ctx['senior'])->get($this->base().'/completion')->assertOk()->assertSee('AI-drafted checklist responses (2)');
        $this->actingAs($this->ctx['staff'])->post($this->base()."/ai-runs/{$run->uuid}/accept-checklist", ['selected' => ['0']])->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ai-runs/{$run->uuid}/accept-checklist", ['selected' => ['0']])->assertSessionHasNoErrors();
        $item = CompletionItem::where('key', 'litigation')->sole();
        $this->assertSame(['open', 'ai'], [$item->status->value, $item->source], 'drafts never complete an item');
        $this->assertStringContainsString("lawyers' confirmations", $item->response);
        $this->assertNull($item->completed_by);
    }

    public function test_quality_review_agent_drafts_notes_raised_by_a_person(): void
    {
        $b = $this->base();
        $this->actingAs($this->ctx['senior'])->post("{$b}/lead-schedules/".$this->fsliId('INV').'/paper')->assertRedirect();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['review_status']]))
            ->push($this->final(['summary' => 'One gap.', 'file_observations' => ['No procedures recorded yet.'], 'notes' => [
                ['working_paper' => 'L-INV', 'note' => 'Record the work performed and a conclusion.'],
                ['working_paper' => 'Z99', 'note' => 'Unknown paper.'],
                ['working_paper' => 'L-INV', 'note' => 'Confirm the opinion before signing.'],
            ]]))]);
        $this->actingAs($this->ctx['staff'])->post("{$b}/ai-runs", ['agent' => 'quality_review', 'task' => 'pre_review'])->assertSessionHasNoErrors();
        $run = $this->ctx['engagement']->aiRuns()->where('agent', 'quality_review')->sole();
        $this->assertSame(['L-INV'], array_column($run->output['notes'], 'working_paper'));
        $this->assertCount(2, $run->output['violations']);
        $this->assertSame(0, ReviewNote::count(), 'nothing raised until a person accepts');

        $this->actingAs($this->ctx['staff'])->post("{$b}/ai-runs/{$run->uuid}/accept-review-notes", ['selected' => ['0']])->assertForbidden();
        $this->actingAs($this->ctx['manager'])->post("{$b}/ai-runs/{$run->uuid}/accept-review-notes", ['selected' => ['0']])->assertSessionHasNoErrors();
        $note = ReviewNote::sole();
        $this->assertSame($this->ctx['manager']->id, $note->raised_by);
        $this->assertStringContainsString('from AI pre-review', $note->body);
    }
}
