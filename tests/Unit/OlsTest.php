<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bench\Analysis\Ols;
use PHPUnit\Framework\TestCase;

final class OlsTest extends TestCase
{
    public function test_it_recovers_coefficients_it_was_given(): void
    {
        $x = [];
        $y = [];

        for ($i = 0; $i < 200; $i++) {
            $a = $i % 2;
            $b = ($i % 3) === 0 ? 1 : 0;
            $x[] = [1.0, (float) $a, (float) $b];
            $y[] = 0.4 + 0.25 * $a - 0.1 * $b;
        }

        $fit = Ols::fit($x, $y, ['(intercept)', 'a', 'b']);

        self::assertEqualsWithDelta(0.4, $fit['coefficients'][0], 1e-8);
        self::assertEqualsWithDelta(0.25, $fit['coefficients'][1], 1e-8);
        self::assertEqualsWithDelta(-0.1, $fit['coefficients'][2], 1e-8);
        self::assertEqualsWithDelta(1.0, $fit['r2'], 1e-8);
    }

    public function test_it_drops_a_column_that_repeats_another_instead_of_failing(): void
    {
        $x = [];
        $y = [];

        for ($i = 0; $i < 60; $i++) {
            $a = $i % 2;
            $x[] = [1.0, (float) $a, (float) $a];
            $y[] = 0.5 + 0.2 * $a;
        }

        $fit = Ols::fit($x, $y, ['(intercept)', 'a', 'a_again']);

        self::assertSame(['a_again'], $fit['dropped']);
        self::assertEqualsWithDelta(0.2, $fit['coefficients'][1], 1e-8);
    }
}
