<?php

declare(strict_types=1);

namespace App\Bench\Analysis;

/**
 * Least squares with heteroskedasticity-robust standard errors.
 *
 * A one-attribute-at-a-time comparison answers "do people with this attribute get
 * different answers", which in a balanced cohort is usually enough. This answers
 * the harder question: does the attribute still move the answer once every other
 * attribute is held fixed. It matters most where two attributes travel together —
 * a name culture and a stated religion, say — because the simple comparison
 * cannot tell which of them the model is reacting to and this can.
 *
 * Errors are robust (HC1) because the outcome is bounded in [0, 1], so its
 * variance is necessarily smaller near the ends than in the middle, and the
 * textbook standard error would be wrong wherever a question is close to
 * unanimous.
 */
final class Ols
{
    /**
     * @param  list<list<float>>  $x  rows of predictors, including the intercept column
     * @param  list<float>  $y
     * @param  list<string>  $names  one per column of $x
     * @return array{names: list<string>, coefficients: list<float>, standardErrors: list<float>, t: list<float>, p: list<float>, r2: float, n: int, dropped: list<string>}
     */
    public static function fit(array $x, array $y, array $names): array
    {
        $n = count($y);
        $k = $n > 0 ? count($x[0]) : 0;

        if ($n === 0 || $k === 0) {
            return ['names' => [], 'coefficients' => [], 'standardErrors' => [], 't' => [], 'p' => [], 'r2' => 0.0, 'n' => 0, 'dropped' => $names];
        }

        [$x, $names, $dropped] = self::dropConstantAndDuplicate($x, $names);
        $k = count($names);

        $xtx = self::gram($x, $k);
        $xty = self::crossProduct($x, $y, $k);
        $inverse = self::invert($xtx);

        if ($inverse === null) {
            return ['names' => [], 'coefficients' => [], 'standardErrors' => [], 't' => [], 'p' => [], 'r2' => 0.0, 'n' => $n, 'dropped' => array_merge($dropped, $names)];
        }

        $beta = [];
        for ($i = 0; $i < $k; $i++) {
            $sum = 0.0;
            for ($j = 0; $j < $k; $j++) {
                $sum += $inverse[$i][$j] * $xty[$j];
            }
            $beta[$i] = $sum;
        }

        $residuals = [];
        $meanY = Stats::mean($y);
        $ssr = 0.0;
        $sst = 0.0;
        foreach ($y as $row => $actual) {
            $fitted = 0.0;
            for ($j = 0; $j < $k; $j++) {
                $fitted += $x[$row][$j] * $beta[$j];
            }
            $residuals[$row] = $actual - $fitted;
            $ssr += $residuals[$row] ** 2;
            $sst += ($actual - $meanY) ** 2;
        }

        // HC1: (X'X)^-1 X' diag(e^2) X (X'X)^-1, scaled by n / (n - k).
        $meat = array_fill(0, $k, array_fill(0, $k, 0.0));
        foreach ($residuals as $row => $residual) {
            $weight = $residual ** 2;
            for ($i = 0; $i < $k; $i++) {
                if ($x[$row][$i] === 0.0) {
                    continue;
                }
                for ($j = 0; $j < $k; $j++) {
                    $meat[$i][$j] += $weight * $x[$row][$i] * $x[$row][$j];
                }
            }
        }

        $scale = $n > $k ? $n / ($n - $k) : 1.0;
        $standardErrors = [];
        $t = [];
        $p = [];

        for ($i = 0; $i < $k; $i++) {
            $variance = 0.0;
            for ($a = 0; $a < $k; $a++) {
                for ($b = 0; $b < $k; $b++) {
                    $variance += $inverse[$i][$a] * $meat[$a][$b] * $inverse[$b][$i];
                }
            }
            $variance *= $scale;
            $se = $variance > 0 ? sqrt($variance) : 0.0;
            $standardErrors[$i] = $se;
            $t[$i] = $se > 0 ? $beta[$i] / $se : 0.0;
            $p[$i] = $se > 0 ? Stats::twoSidedZ($t[$i]) : 1.0;
        }

        return [
            'names' => $names,
            'coefficients' => $beta,
            'standardErrors' => $standardErrors,
            't' => $t,
            'p' => $p,
            'r2' => $sst > 0 ? 1 - $ssr / $sst : 0.0,
            'n' => $n,
            'dropped' => $dropped,
        ];
    }

    /**
     * Drops columns that carry no information: one that never varies (nobody in
     * the sample has that level) and one that exactly repeats an earlier column.
     * Either would make X'X singular, and dropping them by name is more useful
     * than a failed fit.
     *
     * @param  list<list<float>>  $x
     * @param  list<string>  $names
     * @return array{0: list<list<float>>, 1: list<string>, 2: list<string>}
     */
    private static function dropConstantAndDuplicate(array $x, array $names): array
    {
        $k = count($names);
        $keep = [];
        $dropped = [];
        $signatures = [];

        for ($j = 0; $j < $k; $j++) {
            $column = array_column($x, $j);
            $unique = array_unique($column);

            if ($j > 0 && count($unique) < 2) {
                $dropped[] = $names[$j];

                continue;
            }

            $signature = md5(implode(',', $column));
            if (isset($signatures[$signature])) {
                $dropped[] = $names[$j];

                continue;
            }

            $signatures[$signature] = true;
            $keep[] = $j;
        }

        $reduced = [];
        foreach ($x as $row) {
            $out = [];
            foreach ($keep as $j) {
                $out[] = $row[$j];
            }
            $reduced[] = $out;
        }

        return [$reduced, array_values(array_map(fn ($j) => $names[$j], $keep)), $dropped];
    }

    /** @param list<list<float>> $x */
    private static function gram(array $x, int $k): array
    {
        $out = array_fill(0, $k, array_fill(0, $k, 0.0));
        foreach ($x as $row) {
            for ($i = 0; $i < $k; $i++) {
                if ($row[$i] === 0.0) {
                    continue;
                }
                for ($j = $i; $j < $k; $j++) {
                    $out[$i][$j] += $row[$i] * $row[$j];
                }
            }
        }
        for ($i = 0; $i < $k; $i++) {
            for ($j = $i + 1; $j < $k; $j++) {
                $out[$j][$i] = $out[$i][$j];
            }
        }

        return $out;
    }

    /**
     * @param  list<list<float>>  $x
     * @param  list<float>  $y
     * @return list<float>
     */
    private static function crossProduct(array $x, array $y, int $k): array
    {
        $out = array_fill(0, $k, 0.0);
        foreach ($x as $row => $predictors) {
            for ($j = 0; $j < $k; $j++) {
                $out[$j] += $predictors[$j] * $y[$row];
            }
        }

        return $out;
    }

    /** Gauss-Jordan with partial pivoting; null when the matrix is singular. */
    private static function invert(array $matrix): ?array
    {
        $k = count($matrix);
        $augmented = [];

        for ($i = 0; $i < $k; $i++) {
            $augmented[$i] = array_merge($matrix[$i], array_map(fn ($j) => $i === $j ? 1.0 : 0.0, range(0, $k - 1)));
        }

        for ($column = 0; $column < $k; $column++) {
            $pivotRow = $column;
            for ($row = $column + 1; $row < $k; $row++) {
                if (abs($augmented[$row][$column]) > abs($augmented[$pivotRow][$column])) {
                    $pivotRow = $row;
                }
            }

            if (abs($augmented[$pivotRow][$column]) < 1e-10) {
                return null;
            }

            [$augmented[$column], $augmented[$pivotRow]] = [$augmented[$pivotRow], $augmented[$column]];

            $pivot = $augmented[$column][$column];
            for ($j = 0; $j < 2 * $k; $j++) {
                $augmented[$column][$j] /= $pivot;
            }

            for ($row = 0; $row < $k; $row++) {
                if ($row === $column) {
                    continue;
                }
                $factor = $augmented[$row][$column];
                if ($factor === 0.0) {
                    continue;
                }
                for ($j = 0; $j < 2 * $k; $j++) {
                    $augmented[$row][$j] -= $factor * $augmented[$column][$j];
                }
            }
        }

        $inverse = [];
        for ($i = 0; $i < $k; $i++) {
            $inverse[$i] = array_slice($augmented[$i], $k);
        }

        return $inverse;
    }
}
