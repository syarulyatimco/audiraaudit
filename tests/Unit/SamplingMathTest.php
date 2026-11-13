<?php

namespace Tests\Unit;

use App\Enums\SamplingMethod;
use App\Services\Sampling\SampleSelector;
use App\Services\Sampling\SamplingMath as M;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Worked examples for the documented sampling methods (CLAUDE.md §7, TESTING.md checklist item 5). */
class SamplingMathTest extends TestCase
{
    public function test_poisson_upper_limits_match_known_values(): void
    {
        // UL(0, c) = −ln(1 − c); UL(k) solves P(X ≤ k; λ) = 1 − c.
        $this->assertEqualsWithDelta(2.9957, M::poissonUpperLimit(0, 0.95), 0.0001);
        $this->assertEqualsWithDelta(4.7439, M::poissonUpperLimit(1, 0.95), 0.0001);
        $this->assertEqualsWithDelta(6.2958, M::poissonUpperLimit(2, 0.95), 0.0001);
        $this->assertEqualsWithDelta(2.3026, M::poissonUpperLimit(0, 0.90), 0.0001);
        $this->assertEqualsWithDelta(3.8897, M::poissonUpperLimit(1, 0.90), 0.0001);
    }

    public function test_sample_size_formulas(): void
    {
        // MUS: V = RM1,000,000, TM = RM50,000, no expected misstatement, 95% → ceil(1,000,000 × 2.9957 / 50,000) = 60.
        $mus = M::musSize(100_000_000, 5_000_000, 0, 0.95, null);
        $this->assertSame(60, $mus['size']);
        $this->assertSame(1_666_666, $mus['interval_sen']);
        // With EM = RM10,000 and a firm expansion factor of 1.6: ceil(1,000,000 × 2.9957 / (50,000 − 16,000)) = 89.
        $this->assertSame(89, M::musSize(100_000_000, 5_000_000, 1_000_000, 0.95, 1.6)['size']);
        $this->expectException(InvalidArgumentException::class);
        M::musSize(100_000_000, 5_000_000, 1_000_000, 0.95, null); // expansion factor required when misstatement is expected
    }

    public function test_attribute_and_non_statistical_sizes(): void
    {
        $this->assertSame(60, M::attributesSize(0.05, 0.0, 0.95)['size']);   // 2.9957 / 60 ≤ 5%
        $this->assertSame(47, M::attributesSize(0.05, 0.0, 0.90)['size']);   // 2.3026 / 47 ≤ 5%
        $withExpected = M::attributesSize(0.05, 0.01, 0.95);
        $this->assertGreaterThan(60, $withExpected['size']);
        // Non-statistical: ceil(V × AF / TM) = ceil(500,000 × 1.5 / 25,000) = 30.
        $this->assertSame(30, M::nonStatisticalSize(50_000_000, 2_500_000, 1.5)['size']);
    }

    public function test_mus_evaluation_cell_method(): void
    {
        // Interval RM10,000; one 20% overstatement tainting; 95%.
        $e = M::musEvaluation([[500_000, 400_000], [300_000, 300_000]], 1_000_000, 0.95, 0, 5_000_000);
        $this->assertSame(200_000, $e['projected_overstatement_sen']);                 // 0.2 × 1,000,000
        $this->assertSame(2_995_732, $e['basic_precision_sen']);                        // 2.9957 × 1,000,000
        $this->assertEqualsWithDelta(149_627, $e['incremental_allowance_sen'], 2);     // 0.2 × J × (4.74386 − 2.99573 − 1)
        $this->assertEqualsWithDelta(3_345_359, $e['upper_misstatement_limit_sen'], 2);
        $this->assertFalse($e['exceeds_tolerable']);
        $this->assertSame(1, $e['errors']);
    }

    public function test_attributes_evaluation_and_ratio_projection(): void
    {
        $e = M::attributesEvaluation(60, 1, 0.95, 0.05);
        $this->assertEqualsWithDelta(4.7439 / 60, $e['upper_deviation_limit'], 0.0001);
        $this->assertTrue($e['exceeds_tolerable']);
        $this->assertSame(10_000, M::ratioProjection(100_000, 1_000, 1_000_000));
    }

    public function test_selection_is_reproducible_and_selects_key_items(): void
    {
        $items = [];
        for ($i = 1; $i <= 200; $i++) {
            $items[] = ['id' => $i, 'amount' => $i === 7 ? 9_000_000 : 10_000 + $i * 37];
        }
        $a = SampleSelector::select(SamplingMethod::Random, $items, 20, 12345, 5_000_000);
        $b = SampleSelector::select(SamplingMethod::Random, $items, 20, 12345, 5_000_000);
        $c = SampleSelector::select(SamplingMethod::Random, $items, 20, 54321, 5_000_000);
        $this->assertSame($a, $b, 'same seed, same sample');
        $this->assertNotSame(array_column($a, 'id'), array_column($c, 'id'));
        $this->assertSame(['id' => 7, 'reason' => 'key_item', 'stratum' => null, 'point' => null], $a[0]);
        $this->assertCount(21, $a);
        $this->assertCount(21, array_unique(array_column($a, 'id')));

        $mus = SampleSelector::select(SamplingMethod::Mus, $items, 10, 99, null);
        $this->assertContains(7, array_column($mus, 'id'), 'an item larger than the interval is always hit');
        $this->assertSame($mus, SampleSelector::select(SamplingMethod::Mus, $items, 10, 99, null));

        $sys = SampleSelector::select(SamplingMethod::Systematic, $items, 10, 5, null);
        $this->assertCount(10, $sys);
        $strat = SampleSelector::select(SamplingMethod::Stratified, $items, 12, 5, null, [15_000, 50_000]);
        $this->assertSame([1, 2, 3], array_values(array_unique(array_column($strat, 'stratum'))));
        $this->assertCount(12, $strat);
    }
}
