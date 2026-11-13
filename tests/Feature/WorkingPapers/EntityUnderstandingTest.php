<?php

namespace Tests\Feature\WorkingPapers;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Models\WorkingPaper;
use Tests\TestCase;

class EntityUnderstandingTest extends TestCase
{
    public function test_sections_saved_signed_and_invalidated_on_change(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $senior = $this->staff($firm, FirmRole::Senior);
        $engagement = $this->engagement($this->client($partner), $partner, [[$senior, EngagementRole::Senior]]);
        $base = "/engagements/{$engagement->uuid}/understanding";

        $this->actingAs($senior)->get($base)->assertOk()->assertSee('Nature of the entity');
        $wp = WorkingPaper::where('system_key', 'entity_understanding')->sole();
        $this->assertSame('B1', $wp->reference);

        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer'])->assertSessionHasErrors('signoff');

        $this->actingAs($senior)->put($base, ['sections' => [
            'nature_of_entity' => 'Family-owned manufacturer of plastic packaging in Shah Alam; two directors.',
            'industry_regulatory' => 'Regulated under Companies Act 2016; exposed to resin price volatility.',
            'not_a_section' => 'ignored',
        ]])->assertSessionHasNoErrors();
        $this->assertSame(2, $engagement->understandingSections()->count());

        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer'])->assertSessionHasNoErrors();
        $this->assertSame('prepared', $wp->fresh()->status->value);

        $this->actingAs($senior)->put($base, ['sections' => ['nature_of_entity' => 'Updated after director interview.']]);
        $this->assertSame('draft', $wp->fresh()->status->value);

        // the generic WP page redirects to the structured editor
        $this->actingAs($senior)->get("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}")->assertRedirect($base);
    }

    public function test_viewer_without_edit_rights_cannot_save(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $eqr = $this->staff($firm, FirmRole::Partner);
        $engagement = $this->engagement($this->client($partner), $partner, [[$eqr, EngagementRole::EqrReviewer]]);

        $this->actingAs($eqr)->get("/engagements/{$engagement->uuid}/understanding")->assertOk();
        $this->assertSame(0, WorkingPaper::count());
        $this->actingAs($eqr)->put("/engagements/{$engagement->uuid}/understanding", ['sections' => ['nature_of_entity' => 'x']])->assertForbidden();
    }

    /** Regression: permission checks must not rely on a stale, already-loaded team relation. */
    public function test_member_added_after_team_was_loaded_gets_access_immediately(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $senior = $this->staff($firm, FirmRole::Senior);
        $engagement = $this->engagement($this->client($partner), $partner);
        $engagement->load('members');
        $this->tenant()->runForFirm($firm->id, fn () => app(\App\Services\Engagements\EngagementService::class)->addMember($engagement, $senior, EngagementRole::Senior, $partner));

        $this->assertTrue(\App\Support\Authorization\Permissions::allowsOnEngagement($senior, $engagement, 'engagement.edit_working_papers'));
    }
}
