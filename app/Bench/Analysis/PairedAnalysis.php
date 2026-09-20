<?php

declare(strict_types=1);

namespace App\Bench\Analysis;

use App\Bench\Personas\AttributeCatalogue;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The counterfactual arm: every person measured against their own anchor.
 *
 * A pair is the same case, the same anchor, the same everything except one
 * attribute, so the difference between the two answers is what that attribute
 * did. Variation between people — most of the variation in a factorial run —
 * never enters the measurement.
 *
 * Two things make the difference between this working and producing a page of
 * plausible nonsense.
 *
 * The first is that the anchor is asked repeatedly and averaged. Every swap in a
 * base is compared with the same anchor, so a single unlucky anchor answer would
 * push every comparison built on it the same way at once — which is exactly what
 * an early run of this benchmark did, tilting nine of its top ten findings
 * positive. Averaging the anchor over several asks shrinks that shared error
 * instead of letting it masquerade as a dozen separate effects.
 *
 * The second is what a difference is tested against. Repeated identical requests
 * give a measured distribution of answer-to-answer noise, and a pair difference
 * is significant when its average is larger than averages simulated from that
 * noise with the same structure the real pair has — the same number of asks on
 * each side. The assumption left is that the model is no noisier answering about
 * a swapped person than a repeated one; that is the main thing to hold against
 * these p-values.
 */
final class PairedAnalysis
{
    public function __construct(
        private readonly int $resamples = 4000,
        private readonly float $alpha = 0.05,
    ) {}

    /** @return array<string, mixed> */
    public function run(Dataset $data): array
    {
        $cells = $this->cells($data);
        $noise = $this->noise($cells);
        $pairs = $this->pairs($data, $cells);

        return [
            'null' => [
                'samples' => count($noise['overall']),
                'sd' => $noise['overall'] === [] ? null : round(Stats::sd($noise['overall']), 4),
                'mean_abs' => $noise['overall'] === [] ? null : round(Stats::mean(array_map('abs', $noise['overall'])), 4),
                'source' => 'deviations of repeated identical requests from their own average',
            ],
            'overall' => $this->contrasts($pairs['overall'], $noise['overall'], 'overall'),
            'by_measure' => $this->measureContrasts($pairs['by_measure'], $noise['by_measure']),
        ];
    }

    /**
     * Every observation of a person in a case, grouped so repeats sit together.
     *
     * @return array<string, array{n: int, mean: float, measures: array<string, list<float>>, means: list<float>}>
     */
    private function cells(Dataset $data): array
    {
        $grouped = [];
        foreach ($data->rows as $row) {
            $cell = implode('|', [$row['persona_id'], $row['scenario'], $row['condition'], $row['variant']]);
            $grouped[$cell][$row['replicate']][$row['question']] = $row['favourability'];
        }

        $cells = [];
        foreach ($grouped as $cell => $replicates) {
            $means = [];
            $measures = [];
            foreach ($replicates as $answers) {
                $means[] = Stats::mean(array_values($answers));
                foreach ($answers as $question => $value) {
                    $measures[$question][] = $value;
                }
            }

            $cells[$cell] = [
                'n' => count($means),
                'mean' => Stats::mean($means),
                'means' => $means,
                'measures' => $measures,
            ];
        }

        return $cells;
    }

    /**
     * Answer-to-answer noise, as deviations from a cell's own average.
     *
     * Deviations rather than pairwise differences because the observed pairs no
     * longer have a one-against-one shape: the anchor side is an average of
     * several asks. Working in single-observation noise lets the null be
     * simulated with whatever shape each real comparison happens to have.
     *
     * @return array{overall: list<float>, by_measure: array<string, list<float>>}
     */
    private function noise(array $cells): array
    {
        $overall = [];
        $byMeasure = [];

        foreach ($cells as $cell => $entry) {
            if ($entry['n'] < 2) {
                continue;
            }

            $scenario = explode('|', $cell)[1];

            // Deviations from a mean that includes them are too small by
            // sqrt((n-1)/n); the correction puts them back on the scale of the
            // underlying noise.
            $correction = sqrt($entry['n'] / ($entry['n'] - 1));

            foreach ($entry['means'] as $value) {
                $overall[] = ($value - $entry['mean']) * $correction;
            }

            foreach ($entry['measures'] as $question => $values) {
                if (count($values) < 2) {
                    continue;
                }
                $mean = Stats::mean($values);
                foreach ($values as $value) {
                    $byMeasure[$scenario.'.'.$question][] = ($value - $mean) * $correction;
                }
            }
        }

        return ['overall' => $overall, 'by_measure' => $byMeasure];
    }

    /**
     * Each swapped person against their own anchor, both sides averaged over
     * however many times they were asked.
     *
     * @return array{overall: array<string, list<array{diff: float, k: int, r: int}>>, by_measure: array<string, array<string, list<array{diff: float, k: int, r: int}>>>}
     */
    private function pairs(Dataset $data, array $cells): array
    {
        $overall = [];
        $byMeasure = [];

        foreach ($cells as $cell => $entry) {
            [$persona, $scenario, $condition, $variant] = explode('|', $cell);
            $personaId = (int) $persona;

            $pair = $data->pairs[$personaId] ?? null;
            if ($pair === null || $pair['anchor'] || $pair['attribute'] === null) {
                continue;
            }

            $anchorId = $data->anchorOf($personaId);
            $anchor = $anchorId === null
                ? null
                : ($cells[implode('|', [$anchorId, $scenario, $condition, $variant])] ?? null);

            if ($anchor === null) {
                continue;
            }

            $key = implode('|', [$condition, $pair['attribute'], $pair['level']]);
            $overall[$key][] = [
                'diff' => $entry['mean'] - $anchor['mean'],
                'k' => $entry['n'],
                'r' => $anchor['n'],
            ];

            foreach ($entry['measures'] as $question => $values) {
                if (! isset($anchor['measures'][$question])) {
                    continue;
                }

                $byMeasure[$scenario.'.'.$question][$key][] = [
                    'diff' => Stats::mean($values) - Stats::mean($anchor['measures'][$question]),
                    'k' => count($values),
                    'r' => count($anchor['measures'][$question]),
                ];
            }
        }

        return ['overall' => $overall, 'by_measure' => $byMeasure];
    }

    /**
     * @param  array<string, list<array{diff: float, k: int, r: int}>>  $cells
     * @param  list<float>  $noise
     */
    private function contrasts(array $cells, array $noise, string $measure): array
    {
        $tests = [];

        foreach ($cells as $cell => $observations) {
            [$condition, $attribute, $level] = explode('|', $cell);
            $diffs = array_column($observations, 'diff');
            $mean = Stats::mean($diffs);

            [$p, $ci] = $this->test($mean, $observations, $noise, $cell);

            $tests[] = [
                'condition' => $condition,
                'measure' => $measure,
                'factor' => $attribute,
                'factor_label' => AttributeCatalogue::label($attribute),
                'level' => $level,
                'reference' => AttributeCatalogue::reference($attribute),
                'pairs' => count($diffs),
                'delta' => round($mean, 4),
                'sd' => round(Stats::sd($diffs), 4),
                'ci_low' => $ci === null ? null : round($ci[0], 4),
                'ci_high' => $ci === null ? null : round($ci[1], 4),
                'p' => round($p, 5),
                'visible' => $this->visible($condition, $attribute),
            ];
        }

        return $this->rank($tests, $noise);
    }

    private function measureContrasts(array $byMeasure, array $noiseByMeasure): array
    {
        $tests = [];
        foreach ($byMeasure as $measure => $cells) {
            foreach ($this->contrasts($cells, $noiseByMeasure[$measure] ?? [], $measure) as $test) {
                $tests[] = $test;
            }
        }

        return $this->rank($tests, []);
    }

    /**
     * Simulate the null with the comparison's own shape, and read the interval
     * off the same simulation.
     *
     * @param  list<array{diff: float, k: int, r: int}>  $observations
     * @param  list<float>  $noise
     * @return array{0: float, 1: array{0: float, 1: float}|null}
     */
    private function test(float $observed, array $observations, array $noise, string $seedKey): array
    {
        $size = count($noise);
        if ($size < 8 || $observations === []) {
            return [1.0, null];
        }

        $randomizer = new Randomizer(new Mt19937(crc32($seedKey)));
        $pairs = count($observations);
        $target = abs($observed);
        $extreme = 0;
        $simulated = [];

        for ($i = 0; $i < $this->resamples; $i++) {
            $sum = 0.0;
            foreach ($observations as $observation) {
                $swapped = 0.0;
                for ($j = 0; $j < $observation['k']; $j++) {
                    $swapped += $noise[$randomizer->getInt(0, $size - 1)];
                }
                $anchor = 0.0;
                for ($j = 0; $j < $observation['r']; $j++) {
                    $anchor += $noise[$randomizer->getInt(0, $size - 1)];
                }
                $sum += $swapped / $observation['k'] - $anchor / $observation['r'];
            }

            $mean = $sum / $pairs;
            $simulated[] = $mean;
            if (abs($mean) >= $target - 1e-12) {
                $extreme++;
            }
        }

        sort($simulated);
        $lower = $simulated[(int) floor(($this->alpha / 2) * ($this->resamples - 1))];
        $upper = $simulated[(int) ceil((1 - $this->alpha / 2) * ($this->resamples - 1))];

        return [
            ($extreme + 1) / ($this->resamples + 1),
            [$observed + $lower, $observed + $upper],
        ];
    }

    /**
     * Under name-only the model is shown a name and nothing else, so every swap
     * but the two a name carries is invisible to it. Those cells are a negative
     * control that costs nothing extra: whatever fraction of them comes out
     * significant is the rate this method invents findings at.
     */
    private function visible(string $condition, string $attribute): bool
    {
        return match ($condition) {
            'blind' => false,
            'name_only' => in_array($attribute, ['name_culture', 'gender'], true),
            default => true,
        };
    }

    /** @param list<array<string, mixed>> $tests */
    private function rank(array $tests, array $noise): array
    {
        if ($tests === []) {
            return [];
        }

        $floor = $noise === [] ? null : Stats::sd($noise);
        $q = Stats::benjaminiHochberg(array_column($tests, 'p'));

        foreach ($tests as $i => $test) {
            $tests[$i]['q'] = round($q[$i], 5);
            $tests[$i]['significant'] = $q[$i] < $this->alpha;
            $tests[$i]['above_noise'] = $floor === null ? null : abs($test['delta']) >= $floor;
        }

        usort($tests, fn ($a, $b) => $a['q'] <=> $b['q'] ?: abs($b['delta']) <=> abs($a['delta']));

        return $tests;
    }
}
