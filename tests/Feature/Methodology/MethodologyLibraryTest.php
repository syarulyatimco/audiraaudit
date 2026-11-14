<?php

namespace Tests\Feature\Methodology;

use App\Enums\FirmRole;
use App\Models\MethodologyLibrary;
use App\Models\MethodologyStep;
use App\Services\Methodology\MethodologyService;
use App\Services\TrialBalance\SpreadsheetReader;
use App\Support\Spreadsheets\XlsxWriter;
use App\Support\Tenancy\TenantViolation;
use Database\Seeders\MethodologySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MethodologyLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MethodologySeeder::class);
    }

    private function template(): MethodologyLibrary
    {
        return MethodologyLibrary::whereNull('firm_id')->sole();
    }

    public function test_template_is_seeded_once_and_contains_no_standards_text(): void
    {
        $this->seed(MethodologySeeder::class);
        $t = $this->template();
        $this->assertSame(1, MethodologyLibrary::count());
        $this->assertSame(['planning', 'controls', 'substantive'], $t->programmes()->get()->pluck('stage')->map->value->unique()->values()->all());
        // Every step is referenced to a standard by number only; there are no paragraph quotations.
        $steps = MethodologyStep::all();
        $this->assertGreaterThan(100, $steps->count());
        foreach ($steps as $s) {
            $this->assertStringNotContainsString('"', $s->procedure);
            if ($s->isa_refs) {
                $this->assertMatchesRegularExpression('/^(ISA|ISQM|By-Laws)/', $s->isa_refs);
            }
        }
    }

    public function test_firms_read_the_template_but_only_change_their_own_copy(): void
    {
        [$firmA, $partnerA] = $this->makeFirm();
        [$firmB, $partnerB] = $this->makeFirm();
        $associate = $this->staff($firmA);
        $t = $this->template();

        $this->actingAs($associate)->get('/methodology')->assertOk()->assertSee('AUDIRA Audit Methodology');
        $this->actingAs($associate)->get("/methodology/{$t->uuid}")->assertOk()->assertDontSee('/copy"', false);
        $this->actingAs($associate)->post("/methodology/{$t->uuid}/copy", ['name' => 'Mine'])->assertForbidden();

        $this->actingAs($partnerA)->put("/methodology/{$t->uuid}", ['name' => 'Hijack'])->assertSessionHasErrors('library');
        $this->assertSame('AUDIRA Audit Methodology', $t->fresh()->name);

        $this->actingAs($partnerA)->post("/methodology/{$t->uuid}/copy", ['name' => 'Firm A methodology'])->assertSessionHasNoErrors();
        $copy = MethodologyLibrary::where('firm_id', $firmA->id)->sole();
        $this->assertSame($t->id, $copy->based_on_id);
        $this->assertSame($t->programmes()->count(), $copy->programmes()->count());
        $this->assertSame(MethodologyStep::whereHas('programme', fn ($q) => $q->where('library_id', $t->id))->count(), MethodologyStep::whereHas('programme', fn ($q) => $q->where('library_id', $copy->id))->count());

        // Firm B sees the template but not firm A's copy.
        $this->actingAs($partnerB)->get('/methodology')->assertOk()->assertDontSee($copy->uuid);
        $this->actingAs($partnerB)->get("/methodology/{$copy->uuid}")->assertNotFound();

        // The database enforces the same: as firm B, the template is visible but not writable.
        $this->tenant()->set($firmB->id);
        $this->assertSame(1, DB::table('methodology_libraries')->count());
        $this->assertSame(0, DB::table('methodology_libraries')->where('id', $t->id)->update(['name' => 'x']));
        $this->assertSame(0, DB::table('methodology_libraries')->where('id', $copy->id)->update(['name' => 'x']));
        $this->expectException(TenantViolation::class);
        $t->update(['name' => 'x']);
    }

    public function test_programmes_and_steps_are_edited_in_the_grid_and_round_trip_through_excel(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $this->actingAs($partner)->post("/methodology/{$this->template()->uuid}/copy", ['name' => 'Ours'])->assertSessionHasNoErrors();
        $lib = MethodologyLibrary::where('firm_id', $firm->id)->sole();
        $url = "/methodology/{$lib->uuid}";

        // A new programme with validation of routing and references.
        $this->actingAs($partner)->post("{$url}/programmes", ['stage' => 'substantive', 'scope' => 'fsli', 'fsli_codes' => '', 'ref_prefix' => 'S-TR', 'title' => 'X'])->assertSessionHasErrors(['fsli_codes', 'ref_prefix']);
        $this->actingAs($manager)->post("{$url}/programmes", ['stage' => 'substantive', 'scope' => 'fsli', 'fsli_codes' => 'IP', 'ref_prefix' => 'S-IP', 'title' => 'Investment properties'])->assertForbidden();
        $this->actingAs($partner)->post("{$url}/programmes", ['stage' => 'substantive', 'scope' => 'fsli', 'fsli_codes' => 'ip, intang', 'ref_prefix' => 's-ip', 'title' => 'Investment properties'])->assertSessionHasNoErrors();
        $p = $lib->programmes()->where('ref_prefix', 'S-IP')->sole();
        $this->assertSame(['IP', 'INTANG'], $p->fsli_codes);

        $purl = "{$url}/programmes/{$p->uuid}";
        $step = $this->actingAs($partner)->postJson("{$purl}/steps")->assertOk()->json('step');
        $this->actingAs($partner)->patchJson("{$purl}/steps/{$step['uuid']}", ['field' => 'procedure', 'value' => 'Obtain the valuer report and assess the valuer’s competence.'])->assertOk();
        $this->actingAs($partner)->patchJson("{$purl}/steps/{$step['uuid']}", ['field' => 'isa_refs', 'value' => 'ISA 500; ISA 540 (Revised)'])->assertOk();
        $this->actingAs($partner)->patchJson("{$purl}/steps/{$step['uuid']}", ['field' => 'uuid', 'value' => 'x'])->assertStatus(422);
        $this->actingAs($partner)->get($purl)->assertOk()->assertSee('valuer report', false);

        // Excel round trip.
        $res = $this->actingAs($manager)->get("{$purl}/export")->assertOk();
        $rows = app(SpreadsheetReader::class)->read($res->baseResponse->getFile()->getPathname(), 'xlsx', 100, 4000);
        $h = collect($rows)->search(fn ($r) => ($r[0] ?? '') === 'Ref');
        $data = array_slice($rows, $h + 1);
        $data[0][2] = 'E, V';
        $data[] = ['02', 'Inspect title to the properties.', 'R', 'ISA 500', '', ''];
        $file = tempnam(sys_get_temp_dir(), 'm').'.xlsx';
        app(XlsxWriter::class)->write($file, 'S-IP', MethodologyService::XLSX_HEADERS, array_map(fn ($r) => array_pad($r, 6, ''), $data));
        $this->actingAs($partner)->post("{$purl}/import", ['file' => new UploadedFile($file, 'p.xlsx', null, null, true)])
            ->assertSessionHas('status', 'Excel applied: 1 step(s) updated, 1 added, 0 unchanged.');
        $this->assertSame(['E, V', 'R'], $p->steps()->pluck('assertions')->all());

        // The template's programmes are untouched.
        $this->assertFalse($this->template()->programmes()->where('ref_prefix', 'S-IP')->exists());

        // Archiving stops further edits and removes the library from engagement choice.
        $this->actingAs($partner)->post("{$url}/archive")->assertRedirect('/methodology');
        $this->actingAs($partner)->patchJson("{$purl}/steps/{$step['uuid']}", ['field' => 'procedure', 'value' => 'x'])->assertStatus(422);
        $this->assertFalse(MethodologyLibrary::active()->whereKey($lib->id)->exists());
    }
}
