<?php

declare(strict_types=1);

namespace App\Bench\Personas;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A near-orthogonal mixed-level array, used to lay out the factorial cohort.
 *
 * Independent shuffles give columns that are uncorrelated *on average*. In one
 * particular cohort of 160 they are not: some pair of attributes always comes
 * out correlated by luck, and a correlated pair is exactly the case where the
 * benchmark cannot say which of the two the model reacted to. This searches for
 * an assignment where every pair of attributes is as close to crossed as the
 * cohort size allows, so main effects are estimated independently and no pair of
 * levels is left with an empty cell for the intersectional tables.
 *
 * Strength two, which is the useful strength here: the design asks what each
 * attribute does and what pairs of them do, not what happens when five coincide.
 * The construction is the standard swap-based minimisation of the J2 count — a
 * balanced start, then repeated single-value swaps within a column, keeping any
 * swap that lowers pairwise imbalance.
 *
 * What it does not do is make the run smaller. The number of calls here is set
 * by how small an effect has to be visible against the model's own noise, not by
 * how many combinations need covering, and no amount of orthogonality buys
 * statistical power. What it buys is that every call counts for as much as it
 * can.
 */
final class OrthogonalArray
{
    /**
     * @param  list<int>  $levelCounts  one entry per factor
     * @return array{rows: list<list<int>>, imbalance: float, worst_pair: float}
     */
    public static function build(array $levelCounts, int $runs, int $seed, int $iterations = 200_000): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $factors = count($levelCounts);

        $columns = [];
        foreach ($levelCounts as $f => $levels) {
            $columns[$f] = self::balancedColumn($levels, $runs, $randomizer);
        }

        // Joint counts per ordered pair of factors, kept up to date rather than
        // recomputed: a swap touches four cells per pair, so an iteration costs
        // O(factors) instead of O(factors^2 x runs).
        $counts = [];
        $score = 0.0;
        for ($i = 0; $i < $factors; $i++) {
            for ($j = $i + 1; $j < $factors; $j++) {
                $table = array_fill(0, $levelCounts[$i] * $levelCounts[$j], 0);
                for ($r = 0; $r < $runs; $r++) {
                    $table[$columns[$i][$r] * $levelCounts[$j] + $columns[$j][$r]]++;
                }
                $counts[$i][$j] = $table;
                $score += self::tableScore($table, $runs / ($levelCounts[$i] * $levelCounts[$j]));
            }
        }

        for ($step = 0; $step < $iterations && $score > 1e-9; $step++) {
            $f = $randomizer->getInt(0, $factors - 1);
            $a = $randomizer->getInt(0, $runs - 1);
            $b = $randomizer->getInt(0, $runs - 1);

            $levelA = $columns[$f][$a];
            $levelB = $columns[$f][$b];
            if ($levelA === $levelB) {
                continue;
            }

            $delta = 0.0;
            $touched = [];

            for ($g = 0; $g < $factors; $g++) {
                if ($g === $f) {
                    continue;
                }

                [$low, $high] = $f < $g ? [$f, $g] : [$g, $f];
                $width = $levelCounts[$high];
                $expected = $runs / ($levelCounts[$low] * $levelCounts[$high]);
                $table = $counts[$low][$high];

                $keys = $f < $g
                    ? [$levelA * $width + $columns[$g][$a], $levelB * $width + $columns[$g][$b],
                        $levelB * $width + $columns[$g][$a], $levelA * $width + $columns[$g][$b]]
                    : [$columns[$g][$a] * $width + $levelA, $columns[$g][$b] * $width + $levelB,
                        $columns[$g][$a] * $width + $levelB, $columns[$g][$b] * $width + $levelA];

                $before = 0.0;
                foreach (array_unique($keys) as $key) {
                    $before += ($table[$key] - $expected) ** 2;
                }

                $table[$keys[0]]--;
                $table[$keys[1]]--;
                $table[$keys[2]]++;
                $table[$keys[3]]++;

                $after = 0.0;
                foreach (array_unique($keys) as $key) {
                    $after += ($table[$key] - $expected) ** 2;
                }

                $delta += $after - $before;
                $touched[] = [$low, $high, $table];
            }

            if ($delta > 0.0) {
                continue;
            }

            foreach ($touched as [$low, $high, $table]) {
                $counts[$low][$high] = $table;
            }
            [$columns[$f][$a], $columns[$f][$b]] = [$levelB, $levelA];
            $score += $delta;
        }

        $rows = [];
        for ($r = 0; $r < $runs; $r++) {
            $row = [];
            for ($f = 0; $f < $factors; $f++) {
                $row[] = $columns[$f][$r];
            }
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'imbalance' => round($score, 4),
            'worst_pair' => round(self::worstPair($counts, $levelCounts, $runs), 4),
        ];
    }

    /** @return list<int> */
    private static function balancedColumn(int $levels, int $runs, Randomizer $randomizer): array
    {
        $pool = [];
        while (count($pool) < $runs) {
            for ($level = 0; $level < $levels; $level++) {
                $pool[] = $level;
            }
        }

        return $randomizer->shuffleArray(array_slice($pool, 0, $runs));
    }

    /** Summed squared departure of a joint count table from a perfectly crossed one. */
    private static function tableScore(array $table, float $expected): float
    {
        $total = 0.0;
        foreach ($table as $count) {
            $total += ($count - $expected) ** 2;
        }

        return $total;
    }

    /**
     * The worst pair of attributes, as a root-mean-square cell error divided by
     * the cell count that pair should have. Zero is perfectly crossed; one means
     * the typical cell is off by as much as it should contain.
     */
    private static function worstPair(array $counts, array $levelCounts, int $runs): float
    {
        $worst = 0.0;
        $factors = count($levelCounts);

        for ($i = 0; $i < $factors; $i++) {
            for ($j = $i + 1; $j < $factors; $j++) {
                $cells = $levelCounts[$i] * $levelCounts[$j];
                $expected = $runs / $cells;
                if ($expected <= 0) {
                    continue;
                }
                $rmse = sqrt(self::tableScore($counts[$i][$j], $expected) / $cells);
                $worst = max($worst, $rmse / $expected);
            }
        }

        return $worst;
    }
}
