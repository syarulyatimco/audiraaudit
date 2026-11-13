<?php

namespace Tests\Feature\TrialBalance;

use App\Enums\EngagementRole;
use App\Models\AccountMapping;

class MappingAndLeadScheduleTest extends TrialBalanceTestCase
{
    public function test_lead_schedules_and_statement_checks(): void
    {
        $this->importTb();
        $prior = self::CY;
        $prior[1][2] = '30,000.00';
        $prior[8][3] = '281,000.00'; // revenue lower in PY; keep balanced
        $this->importTb($prior, 'prior');

        $this->actingAs($this->ctx['staff'])->get($this->base().'/lead-schedules')->assertOk()->assertSee('10 unmapped account(s)');
        $this->mapAll();

        $data = app(\App\Services\TrialBalance\LeadScheduleService::class)->build($this->ctx['engagement']);
        $cash = $data['fslis'][$this->fsliId('CASH')];
        $this->assertSame(4500000, $cash['cy']);
        $this->assertSame(3000000, $cash['py']);
        $this->assertSame(29600000, $data['fslis'][$this->fsliId('REV')]['cy_adj'], 'Revenue shown as positive (credit-normal)');
        $this->assertSame(15000000, $data['cy']['profit']);            // 296k - 120k - 26k
        $this->assertSame(30500000, $data['cy']['assets']);            // 45 + 80 + 60 + 120; the overdraft is a liability
        $this->assertSame(0, $data['checks']['sofp_difference']);
        $this->assertTrue($data['checks']['tb_balances']);

        $this->actingAs($this->ctx['staff'])->get($this->base().'/lead-schedules')->assertOk()->assertSee('Assets = liabilities + equity + profit');
        $this->actingAs($this->ctx['staff'])->get($this->base().'/lead-schedules/'.$this->fsliId('CASH'))->assertOk()->assertSee('Cash at Bank - Maybank');
    }

    public function test_contra_balance_flagged(): void
    {
        $this->importTb();
        $this->mapAll();
        // map the overdraft into Cash: a credit balance in a debit-normal FSLI
        $this->actingAs($this->ctx['staff'])->put($this->base().'/mapping', ['map' => ['1010' => $this->fsliId('CASH')]]);
        $data = app(\App\Services\TrialBalance\LeadScheduleService::class)->build($this->ctx['engagement']);
        $this->assertSame(['1010'], $data['fslis'][$this->fsliId('CASH')]['contra']);
        $this->assertFalse($data['fslis'][$this->fsliId('CASH')]['opposite'], 'Cash is still a net debit');
    }

    /** Regression: every rule must compile, contain no control characters, and match sensible names. */
    public function test_mapping_rules_are_valid_regexes(): void
    {
        foreach (config('audira.trial_balance.mapping_rules') as $pattern => $code) {
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $pattern);
            $this->assertNotFalse(@preg_match($pattern.'i', ''), "Invalid pattern {$pattern}");
        }
        $match = function (string $name) {
            foreach (config('audira.trial_balance.mapping_rules') as $p => $c) {
                if (preg_match($p.'i', $name)) {
                    return $c;
                }
            }

            return 'none';
        };
        $this->assertSame('CASH', $match('Bank - Maintenance account (Public Bank)'));
        $this->assertSame('FIN', $match('Term loan interest'));
        $this->assertNull($match('Term loan - CIMB'));
        $this->assertSame('COS', $match('Factory overheads'));
        $this->assertNull($match('Bank overdraft - CIMB'));
        $this->assertSame('TR', $match('Allowance for expected credit losses'));
    }

    public function test_suggestions_rules_and_carry_forward_require_acceptance(): void
    {
        ['partner' => $partner, 'client' => $client, 'senior' => $senior] = $this->ctx;
        // prior-year engagement for the same client, with a non-obvious mapping for 6000
        $previous = $this->engagement($client, $partner, [[$senior, EngagementRole::Senior]]);
        $previous->forceFill(['code' => 'ENG-2024-0009', 'period_end' => '2024-12-31', 'period_start' => '2024-01-01'])->save();
        $this->importTb(self::CY, 'current', $previous);
        $this->actingAs($senior)->put($this->base($previous).'/mapping', ['map' => ['6000' => $this->fsliId('COS', $previous)]]);

        $this->importTb();
        $s = app(\App\Services\TrialBalance\MappingService::class)->suggestions($this->ctx['engagement']);

        $this->assertSame('carried_forward', $s['6000']['source']);
        $this->assertSame($this->fsliId('COS'), $s['6000']['fsli_id']);
        $this->assertSame('rule', $s['1000']['source']);
        $this->assertSame($this->fsliId('CASH'), $s['1000']['fsli_id']);
        $this->assertArrayNotHasKey('1010', $s, 'Overdraft deliberately left for manual judgement');
        $this->assertSame(0, $this->ctx['engagement']->accountMappings()->count(), 'Nothing applied automatically');

        $this->actingAs($senior)->post($this->base().'/mapping/accept', ['codes' => ['1000', '6000']])->assertSessionHasNoErrors();
        $this->assertSame('rule', AccountMapping::where('engagement_id', $this->ctx['engagement']->id)->where('account_code', '1000')->value('source')->value);
        $this->assertSame('carried_forward', AccountMapping::where('engagement_id', $this->ctx['engagement']->id)->where('account_code', '6000')->value('source')->value);
    }

    public function test_mapping_rejects_foreign_fsli_and_unknown_accounts(): void
    {
        ['partner' => $partner, 'client' => $client] = $this->ctx;
        $this->importTb();
        $other = $this->engagement($client, $partner);
        $this->tenant()->runForFirm($this->ctx['firm']->id, fn () => app(\App\Services\TrialBalance\FsliService::class)->ensureInitialised($other, $partner));

        $this->actingAs($this->ctx['staff'])->put($this->base().'/mapping', ['map' => ['1000' => $this->fsliId('CASH', $other)]])->assertSessionHasErrors('mapping');
        $this->actingAs($this->ctx['staff'])->put($this->base().'/mapping', ['map' => ['NOPE' => $this->fsliId('CASH')]])->assertSessionHasErrors('mapping');
        $this->actingAs($this->ctx['staff'])->get($this->base().'/mapping')->assertOk()->assertSee('mapping suggestion');
    }

    public function test_fsli_management(): void
    {
        $this->importTb();
        $this->mapAll();
        $senior = $this->ctx['senior'];
        $this->actingAs($senior)->post($this->base().'/fslis', ['code' => 'bio', 'name' => 'Biological assets', 'classification' => 'non_current_asset'])->assertSessionHasNoErrors();
        $this->actingAs($senior)->post($this->base().'/fslis', ['code' => 'BIO', 'name' => 'Dup', 'classification' => 'non_current_asset'])->assertSessionHasErrors('code');
        $this->actingAs($senior)->delete($this->base().'/fslis/'.$this->fsliId('CASH'))->assertSessionHasErrors('fsli');
        $this->actingAs($senior)->delete($this->base().'/fslis/'.$this->fsliId('BIO'))->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['staff'])->post($this->base().'/fslis', ['code' => 'X', 'name' => 'X', 'classification' => 'expense'])->assertForbidden();
    }

    public function test_export_csv_neutralises_formulas(): void
    {
        $rows = self::CY;
        $rows[10][1] = '=HYPERLINK("http://evil")';
        $this->importTb($rows);
        $csv = $this->actingAs($this->ctx['staff'])->get($this->base().'/trial-balance/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('1000,"Cash at Bank - Maybank",,,0.00,45000.00,0.00,45000.00', $csv);
    }

    public function test_strata_client_gets_jmb_template_and_rules(): void
    {
        ['partner' => $partner, 'senior' => $senior] = $this->ctx;
        $jmb = $this->client($partner, attrs: ['name' => 'Taman Seri JMB', 'entity_type' => 'jmb_mc']);
        $e = $this->engagement($jmb, $partner, [[$senior, \App\Enums\EngagementRole::Senior]]);
        $this->importTb([
            ['A/C No', 'Account Description', 'Debit', 'Credit'],
            ['1000', 'Bank - Maintenance account (Public Bank)', '186,450.20', ''],
            ['1010', 'Bank - Sinking fund account (Public Bank)', '412,300.00', ''],
            ['3100', 'Accumulated surplus', '', '156,750.20'],
            ['4000', 'Maintenance charges', '', '420,000.00'],
            ['4010', 'Sinking fund contributions', '', '42,000.00'],
            ['5000', 'Security services', '20,000.00', ''],
        ], 'current', $e);

        $this->assertTrue($e->fslis()->where('code', 'SF')->exists());
        $this->assertFalse($e->fslis()->where('code', 'REV')->exists());
        $s = app(\App\Services\TrialBalance\MappingService::class)->suggestions($e);
        $codes = $e->fslis()->pluck('code', 'id');
        $this->assertSame(['1000' => 'CASH', '1010' => 'CASH-SF', '3100' => 'MF', '4000' => 'MC-INC', '4010' => 'SF-INC', '5000' => 'SEC'],
            collect($s)->map(fn ($x) => $codes[$x['fsli_id']])->sortKeys()->all());
    }
}
