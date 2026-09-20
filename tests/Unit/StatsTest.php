<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bench\Analysis\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function test_permutation_test_does_not_find_a_difference_that_is_not_there(): void
    {
        $a = [0.5, 0.52, 0.48, 0.51, 0.49, 0.5, 0.53, 0.47];
        $b = [0.51, 0.49, 0.5, 0.5, 0.52, 0.48, 0.49, 0.51];

        self::assertGreaterThan(0.2, Stats::permutationP($a, $b, 2000));
    }

    public function test_permutation_test_finds_a_difference_that_is(): void
    {
        $a = [0.20, 0.22, 0.18, 0.21, 0.19, 0.20, 0.23, 0.17];
        $b = [0.71, 0.69, 0.70, 0.70, 0.72, 0.68, 0.69, 0.71];

        self::assertLessThan(0.01, Stats::permutationP($a, $b, 2000));
    }

    public function test_benjamini_hochberg_never_lowers_a_p_value_and_stays_monotonic(): void
    {
        $p = [0.001, 0.008, 0.039, 0.041, 0.042, 0.06, 0.074, 0.205, 0.212, 0.216, 0.222, 0.43, 0.57, 0.81, 1.0];
        $q = Stats::benjaminiHochberg($p);

        foreach ($p as $i => $value) {
            self::assertGreaterThanOrEqual($value, $q[$i] + 1e-9, "q fell below p at {$i}");
        }

        $sorted = $q;
        sort($sorted);
        self::assertSame($sorted, $q, 'q-values should rise with p when p is already sorted');
    }

    public function test_bootstrap_interval_brackets_the_difference_it_was_given(): void
    {
        $a = array_fill(0, 40, 0.7);
        $b = array_fill(0, 40, 0.4);

        [$low, $high] = Stats::bootstrapDiffCi($a, $b, 1000);

        self::assertEqualsWithDelta(0.3, $low, 1e-9);
        self::assertEqualsWithDelta(0.3, $high, 1e-9);
    }

    public function test_normal_cdf_matches_the_values_everyone_knows(): void
    {
        self::assertEqualsWithDelta(0.5, Stats::normalCdf(0.0), 1e-6);
        self::assertEqualsWithDelta(0.977250, Stats::normalCdf(2.0), 1e-4);
        self::assertEqualsWithDelta(0.05, Stats::twoSidedZ(1.959964), 1e-3);
    }
}
