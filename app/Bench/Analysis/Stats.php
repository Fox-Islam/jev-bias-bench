<?php

declare(strict_types=1);

namespace App\Bench\Analysis;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The statistics the report needs, with no assumption that answers are normal.
 *
 * Model answers are bounded in [0, 1] and often pile up at the ends, so a t-test
 * on them is a guess dressed as a result. Differences are tested by shuffling the
 * group labels instead: if a gap survives being compared against the gaps you get
 * from relabelling at random, it is real. Intervals come from the bootstrap for
 * the same reason.
 */
final class Stats
{
    /** @param list<float> $values */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @param list<float> $values Sample standard deviation. */
    public static function sd(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = self::mean($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }

    /** @param list<float> $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Standardised difference between two groups, pooling their spread. Reported
     * alongside the raw gap because a two-point gap means something different on
     * a question everyone answers the same way than on one that varies wildly.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cohensD(array $a, array $b): float
    {
        $na = count($a);
        $nb = count($b);
        if ($na < 2 || $nb < 2) {
            return 0.0;
        }

        $pooled = sqrt(((($na - 1) * self::sd($a) ** 2) + (($nb - 1) * self::sd($b) ** 2)) / ($na + $nb - 2));

        return $pooled > 0.0 ? (self::mean($a) - self::mean($b)) / $pooled : 0.0;
    }

    /**
     * Two-sided permutation test on the difference in means. The +1 in numerator
     * and denominator keeps p away from exactly zero, which no finite number of
     * shuffles can justify.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function permutationP(array $a, array $b, int $permutations, int $seed = 12345): float
    {
        $na = count($a);
        $nb = count($b);
        if ($na === 0 || $nb === 0) {
            return 1.0;
        }

        $observed = abs(self::mean($a) - self::mean($b));
        $pool = array_merge($a, $b);
        $randomizer = new Randomizer(new Mt19937($seed));
        $extreme = 0;

        for ($i = 0; $i < $permutations; $i++) {
            $shuffled = $randomizer->shuffleArray($pool);
            $left = array_slice($shuffled, 0, $na);
            $right = array_slice($shuffled, $na);

            if (abs(self::mean($left) - self::mean($right)) >= $observed - 1e-12) {
                $extreme++;
            }
        }

        return ($extreme + 1) / ($permutations + 1);
    }

    /**
     * Percentile bootstrap interval for the difference in means.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return array{0: float, 1: float}
     */
    public static function bootstrapDiffCi(array $a, array $b, int $samples, float $alpha = 0.05, int $seed = 54321): array
    {
        $na = count($a);
        $nb = count($b);
        if ($na === 0 || $nb === 0) {
            return [0.0, 0.0];
        }

        $randomizer = new Randomizer(new Mt19937($seed));
        $diffs = [];

        for ($i = 0; $i < $samples; $i++) {
            $sumA = 0.0;
            for ($j = 0; $j < $na; $j++) {
                $sumA += $a[$randomizer->getInt(0, $na - 1)];
            }
            $sumB = 0.0;
            for ($j = 0; $j < $nb; $j++) {
                $sumB += $b[$randomizer->getInt(0, $nb - 1)];
            }
            $diffs[] = $sumA / $na - $sumB / $nb;
        }

        sort($diffs);
        $lower = (int) floor(($alpha / 2) * ($samples - 1));
        $upper = (int) ceil((1 - $alpha / 2) * ($samples - 1));

        return [$diffs[$lower], $diffs[$upper]];
    }

    /**
     * Benjamini-Hochberg. A run asks thousands of questions of the same data, so
     * uncorrected p-values would hand back a page of "findings" that are just the
     * tail of the null. Controlling the false discovery rate is the difference
     * between a report and a list of coincidences.
     *
     * @param  list<float>  $pValues
     * @return list<float> q-values in the same order
     */
    public static function benjaminiHochberg(array $pValues): array
    {
        $n = count($pValues);
        if ($n === 0) {
            return [];
        }

        $indexed = [];
        foreach ($pValues as $i => $p) {
            $indexed[] = [$p, $i];
        }
        usort($indexed, fn ($x, $y) => $x[0] <=> $y[0]);

        $q = array_fill(0, $n, 1.0);
        $previous = 1.0;

        for ($rank = $n; $rank >= 1; $rank--) {
            [$p, $original] = $indexed[$rank - 1];
            $previous = min($previous, $p * $n / $rank);
            $q[$original] = min(1.0, $previous);
        }

        return $q;
    }

    /** Abramowitz and Stegun 7.1.26 — enough precision for a p-value. */
    public static function normalCdf(float $z): float
    {
        $sign = $z < 0 ? -1 : 1;
        $x = abs($z) / sqrt(2);
        $t = 1 / (1 + 0.3275911 * $x);
        $erf = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return 0.5 * (1 + $sign * $erf);
    }

    public static function twoSidedZ(float $z): float
    {
        return 2 * (1 - self::normalCdf(abs($z)));
    }
}
