<?php

namespace App\Services\Sampling;

use InvalidArgumentException;

/**
 * Documented sampling mathematics (CLAUDE.md §7). Every result carries the formula and inputs so
 * the working paper can show how a size or bound was reached.
 *
 * Statistical methods use the Poisson distribution: the upper limit UL(k, c) is the mean λ for
 * which P(X ≤ k; λ) = 1 − c (computed numerically, not read from a table). Factors that come from
 * a firm's methodology (MUS expansion factor, non-statistical assurance factor) are inputs that
 * the auditor must supply and justify — AUDIRA ships no defaults for them.
 */
final class SamplingMath
{
    /** Poisson upper limit for k observed errors at confidence c (0 < c < 1). */
    public static function poissonUpperLimit(int $k, float $confidence): float
    {
        if ($k < 0 || $confidence <= 0 || $confidence >= 1) {
            throw new InvalidArgumentException('Invalid Poisson inputs.');
        }
        if ($k === 0) {
            return -log(1 - $confidence);
        }
        $target = 1 - $confidence;
        $lo = 0.0;
        $hi = $k + 10 + 10 * sqrt($k + 1);
        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            self::poissonCdf($k, $mid) > $target ? $lo = $mid : $hi = $mid;
        }

        return ($lo + $hi) / 2;
    }

    public static function poissonCdf(int $k, float $lambda): float
    {
        $term = exp(-$lambda);
        $sum = $term;
        for ($i = 1; $i <= $k; $i++) {
            $term *= $lambda / $i;
            $sum += $term;
        }

        return min(1.0, $sum);
    }

    /**
     * MUS sample size: n = ⌈ V × R / (TM − EM × EF) ⌉, R = UL(0, c).
     *
     * @return array{size: int, formula: string, inputs: array, interval_sen: int}
     */
    public static function musSize(int $populationSen, int $tolerableSen, int $expectedSen, float $confidence, ?float $expansionFactor): array
    {
        if ($populationSen <= 0 || $tolerableSen <= 0) {
            throw new InvalidArgumentException('Population value and tolerable misstatement must be positive.');
        }
        if ($expectedSen > 0 && ($expansionFactor === null || $expansionFactor <= 0)) {
            throw new InvalidArgumentException('An expansion factor from your firm methodology is required when misstatement is expected.');
        }
        $r = self::poissonUpperLimit(0, $confidence);
        $denominator = $tolerableSen - $expectedSen * ($expectedSen > 0 ? $expansionFactor : 0);
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Expected misstatement is too close to tolerable misstatement for MUS.');
        }
        $n = max(1, (int) ceil($populationSen * $r / $denominator));

        return [
            'size' => $n,
            'formula' => 'n = ceil(V × R / (TM − EM × EF)), R = Poisson UL(0, confidence)',
            'inputs' => ['V_sen' => $populationSen, 'TM_sen' => $tolerableSen, 'EM_sen' => $expectedSen, 'confidence' => $confidence,
                'R' => round($r, 4), 'EF' => $expectedSen > 0 ? $expansionFactor : null],
            'interval_sen' => intdiv($populationSen, $n),
        ];
    }

    /**
     * Attribute (controls) sample size: the smallest n with UL(k, c) / n ≤ TDR, where
     * k = round(n × EDR) deviations are expected.
     *
     * @return array{size: int, formula: string, inputs: array}
     */
    public static function attributesSize(float $tolerableRate, float $expectedRate, float $confidence): array
    {
        if ($tolerableRate <= 0 || $tolerableRate >= 1 || $expectedRate < 0 || $expectedRate >= $tolerableRate) {
            throw new InvalidArgumentException('The expected deviation rate must be below the tolerable rate.');
        }
        for ($n = 1; $n <= 5000; $n++) {
            $k = (int) round($n * $expectedRate);
            if (self::poissonUpperLimit($k, $confidence) / $n <= $tolerableRate) {
                return [
                    'size' => $n,
                    'formula' => 'smallest n with Poisson UL(k, confidence) / n ≤ TDR, k = round(n × EDR)',
                    'inputs' => ['TDR' => $tolerableRate, 'EDR' => $expectedRate, 'confidence' => $confidence, 'k' => $k],
                ];
            }
        }
        throw new InvalidArgumentException('The required sample size exceeds 5,000 items; reconsider the parameters.');
    }

    /**
     * Non-statistical monetary sample size: n = ⌈ V × AF / TM ⌉, AF = assurance factor from the
     * firm's methodology (documented by the auditor).
     *
     * @return array{size: int, formula: string, inputs: array}
     */
    public static function nonStatisticalSize(int $populationSen, int $tolerableSen, float $assuranceFactor): array
    {
        if ($populationSen <= 0 || $tolerableSen <= 0 || $assuranceFactor <= 0) {
            throw new InvalidArgumentException('Population value, tolerable misstatement and assurance factor must be positive.');
        }

        return [
            'size' => max(1, (int) ceil($populationSen * $assuranceFactor / $tolerableSen)),
            'formula' => 'n = ceil(V × AF / TM), AF from the firm methodology',
            'inputs' => ['V_sen' => $populationSen, 'TM_sen' => $tolerableSen, 'AF' => $assuranceFactor],
        ];
    }

    /**
     * MUS evaluation (cell method upper bound). $hits are [book_sen, audited_sen] for items selected
     * by monetary units (not key items). Overstatement taintings are ranked; projected misstatement
     * = Σ t × J; basic precision = UL(0) × J; incremental allowance = Σ t_i × J × (ΔUL_i − 1).
     *
     * @param  list<array{0: int, 1: int}>  $hits
     */
    public static function musEvaluation(array $hits, int $intervalSen, float $confidence, int $keyItemMisstatementSen, int $tolerableSen): array
    {
        $over = [];
        $underProjected = 0;
        foreach ($hits as [$book, $audited]) {
            if ($book <= 0) {
                continue;
            }
            $t = ($book - $audited) / $book;
            if ($t > 0) {
                $over[] = min(1.0, $t);
            } elseif ($t < 0) {
                $underProjected += (int) round(max(-1.0, $t) * $intervalSen);
            }
        }
        rsort($over);
        $basic = self::poissonUpperLimit(0, $confidence) * $intervalSen;
        $projected = 0.0;
        $incremental = 0.0;
        foreach ($over as $i => $t) {
            $projected += $t * $intervalSen;
            $delta = self::poissonUpperLimit($i + 1, $confidence) - self::poissonUpperLimit($i, $confidence);
            $incremental += $t * $intervalSen * ($delta - 1);
        }
        $upper = (int) round($projected + $basic + $incremental) + max(0, $keyItemMisstatementSen);

        return [
            'method' => 'MUS cell evaluation (Poisson)',
            'interval_sen' => $intervalSen,
            'errors' => count($over),
            'projected_overstatement_sen' => (int) round($projected),
            'projected_understatement_sen' => $underProjected,
            'basic_precision_sen' => (int) round($basic),
            'incremental_allowance_sen' => (int) round($incremental),
            'key_item_misstatement_sen' => $keyItemMisstatementSen,
            'projected_total_sen' => (int) round($projected) + $keyItemMisstatementSen,
            'upper_misstatement_limit_sen' => $upper,
            'tolerable_sen' => $tolerableSen,
            'exceeds_tolerable' => $upper > $tolerableSen,
        ];
    }

    /** Ratio projection of sample misstatement to the (non-key) population. */
    public static function ratioProjection(int $sampleBookSen, int $sampleMisstatementSen, int $populationSen): int
    {
        return $sampleBookSen === 0 ? 0 : (int) round($sampleMisstatementSen / $sampleBookSen * $populationSen);
    }

    public static function attributesEvaluation(int $sampleSize, int $deviations, float $confidence, float $tolerableRate): array
    {
        $upper = $sampleSize > 0 ? self::poissonUpperLimit($deviations, $confidence) / $sampleSize : 1.0;

        return [
            'method' => 'Attributes evaluation (Poisson upper deviation limit)',
            'sample_size' => $sampleSize,
            'deviations' => $deviations,
            'sample_rate' => $sampleSize > 0 ? round($deviations / $sampleSize, 4) : null,
            'upper_deviation_limit' => round($upper, 4),
            'tolerable_rate' => $tolerableRate,
            'exceeds_tolerable' => $upper > $tolerableRate,
        ];
    }
}
