<?php

namespace App\Services\Sampling;

use App\Enums\SamplingMethod;
use InvalidArgumentException;

/**
 * Reproducible sample selection. Given the same population (same order and amounts), method,
 * parameters and seed, the same items are selected — anyone can re-perform it. Randomness comes
 * only from PHP's Mersenne Twister (MT19937) seeded with the stored seed.
 *
 * Input items: list of ['id' => int, 'amount' => int (sen)] in population order.
 * Output: list of ['id', 'reason', 'stratum' => ?int, 'point' => ?int] in selection order.
 */
final class SampleSelector
{
    public const ALGORITHM = 'php-mt19937 v1';

    /**
     * @param  list<array{id: int, amount: int}>  $items
     * @return list<array{id: int, reason: string, stratum: ?int, point: ?int}>
     */
    public static function select(SamplingMethod $method, array $items, int $size, int $seed, ?int $keyThreshold = null, array $strata = []): array
    {
        if ($method->isManual()) {
            throw new InvalidArgumentException('Judgemental and haphazard samples are chosen by the auditor.');
        }
        mt_srand($seed, MT_RAND_MT19937);
        $out = [];
        $rest = [];
        foreach ($items as $it) {
            if ($keyThreshold !== null && abs($it['amount']) >= $keyThreshold) {
                $out[] = ['id' => $it['id'], 'reason' => 'key_item', 'stratum' => null, 'point' => null];
            } else {
                $rest[] = $it;
            }
        }

        $picked = match ($method) {
            SamplingMethod::Mus => self::mus($rest, $size),
            SamplingMethod::Random => array_map(fn ($it) => ['id' => $it['id'], 'reason' => 'random', 'stratum' => null, 'point' => null], self::randomSubset($rest, $size)),
            SamplingMethod::Systematic => self::systematic($rest, $size),
            SamplingMethod::Stratified => self::stratified($rest, $size, $strata),
        };
        mt_srand(); // do not leave the global generator in a predictable state

        return array_merge($out, $picked);
    }

    /** Systematic PPS selection on cumulative monetary units with a random start. Items ≤ 0 are not selectable by MUS. */
    private static function mus(array $items, int $size): array
    {
        $positive = array_values(array_filter($items, fn ($it) => $it['amount'] > 0));
        $total = array_sum(array_column($positive, 'amount'));
        if ($total <= 0 || $size <= 0) {
            return [];
        }
        $interval = max(1, intdiv($total, $size));
        $point = mt_rand(1, $interval);
        $out = [];
        $cum = 0;
        $i = 0;
        foreach ($positive as $it) {
            $cum += $it['amount'];
            $hit = null;
            while ($point <= $cum && $i < $size) {
                $hit ??= $point;   // first unit that lands on this item
                $point += $interval;
                $i++;
            }
            if ($hit !== null) {
                $out[] = ['id' => $it['id'], 'reason' => 'mus', 'stratum' => null, 'point' => $hit];
            }
        }

        return $out;
    }

    /** Random selection without replacement (partial Fisher–Yates), returned in population order. */
    private static function randomSubset(array $items, int $size): array
    {
        $n = count($items);
        $size = min($size, $n);
        $idx = range(0, $n - 1);
        for ($i = 0; $i < $size; $i++) {
            $j = mt_rand($i, $n - 1);
            [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
        }
        $chosen = array_slice($idx, 0, $size);
        sort($chosen);

        return array_map(fn ($k) => $items[$k], $chosen);
    }

    private static function systematic(array $items, int $size): array
    {
        $n = count($items);
        if ($n === 0 || $size <= 0) {
            return [];
        }
        $size = min($size, $n);
        $step = $n / $size;
        $start = mt_rand(0, max(0, (int) floor($step * 1000) - 1)) / 1000;
        $out = [];
        $seen = [];
        for ($i = 0; $i < $size; $i++) {
            $k = min($n - 1, (int) floor($start + $i * $step));
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = ['id' => $items[$k]['id'], 'reason' => 'systematic', 'stratum' => null, 'point' => null];
            }
        }

        return $out;
    }

    /** Strata by absolute amount (upper bounds ascending; the last stratum is open-ended); size allocated by stratum value. */
    private static function stratified(array $items, int $size, array $bounds): array
    {
        sort($bounds);
        $groups = [];
        foreach ($items as $it) {
            $s = 0;
            while ($s < count($bounds) && abs($it['amount']) > $bounds[$s]) {
                $s++;
            }
            $groups[$s][] = $it;
        }
        ksort($groups);
        $values = array_map(fn ($g) => array_sum(array_map(fn ($it) => abs($it['amount']), $g)), $groups);
        $total = array_sum($values) ?: 1;
        // Largest-remainder allocation by value, capped at each stratum's item count, surplus redistributed.
        $size = min($size, count($items));
        $counts = array_map('count', $groups);
        $alloc = array_fill_keys(array_keys($groups), 0);
        $remaining = $size;
        $open = array_keys($groups);
        while ($remaining > 0 && $open) {
            $openValue = array_sum(array_intersect_key($values, array_flip($open))) ?: 1;
            $rema = [];
            $given = 0;
            foreach ($open as $s) {
                $exact = $remaining * $values[$s] / $openValue;
                $add = min((int) floor($exact), $counts[$s] - $alloc[$s]);
                $alloc[$s] += $add;
                $given += $add;
                $rema[$s] = $exact - floor($exact);
            }
            arsort($rema);
            foreach (array_keys($rema) as $s) {
                if ($given >= $remaining) {
                    break;
                }
                if ($alloc[$s] < $counts[$s]) {
                    $alloc[$s]++;
                    $given++;
                }
            }
            $remaining -= $given;
            $open = array_values(array_filter($open, fn ($s) => $alloc[$s] < $counts[$s]));
            if ($given === 0) {
                break;
            }
        }
        // Every non-empty stratum is represented when the size allows.
        foreach ($alloc as $s => $a) {
            if ($a === 0 && $size >= count($groups)) {
                $donor = array_search(max($alloc), $alloc, true);
                if ($alloc[$donor] > 1) {
                    $alloc[$donor]--;
                    $alloc[$s] = 1;
                }
            }
        }
        $out = [];
        foreach ($groups as $s => $g) {
            foreach (self::randomSubset($g, min($alloc[$s], count($g))) as $it) {
                $out[] = ['id' => $it['id'], 'reason' => 'stratified', 'stratum' => $s + 1, 'point' => null];
            }
        }

        return $out;
    }
}
