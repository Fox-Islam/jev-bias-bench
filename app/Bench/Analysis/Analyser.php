<?php

declare(strict_types=1);

namespace App\Bench\Analysis;

use App\Bench\Personas\AttributeCatalogue;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Turns a finished run into the report.
 *
 * The order of the sections is the order the questions have to be answered in.
 * How noisy is the model when nothing changes? Do these questions respond to the
 * merits at all? Does attaching a person to a case move the answer? Which
 * attributes move it, and do they still move it with everything else held fixed?
 * And finally the check that keeps the rest honest: the same tests run on the
 * condition where the model was never told who the person was, where every
 * "finding" is by construction a false one.
 */
final class Analyser
{
    private const HEADLINE_FACTORS = [
        'name_culture', 'gender', 'ethnicity', 'religion', 'disability',
        'immigration_status', 'age_band', 'bmi_band', 'accent', 'neighbourhood',
    ];

    public function __construct(
        private readonly int $permutations = 4000,
        private readonly int $bootstrapSamples = 2000,
        private readonly float $alpha = 0.05,
    ) {}

    /** @return array<string, mixed> */
    public function report(Run $run): array
    {
        $data = new Dataset($run);
        $noise = $this->noiseFloor($data);

        $report = [
            'run' => $this->meta($run, $data),
            'design' => $run->design,
            'noise_floor' => $noise,
            'validity' => $this->validity($data, $noise),
            'conditions' => $this->conditionShift($data),
        ];

        if ($data->isCounterfactual()) {
            $paired = (new PairedAnalysis($this->permutations, $this->alpha))->run($data);
            $report['paired'] = $paired;
            $report['calibration'] = $this->pairedCalibration($paired);

            return $report + ['generated_at' => now()->toIso8601String()];
        }

        $contrasts = $this->contrasts($data, $noise['pooled_sd']);

        return $report + [
            'contrasts' => $contrasts,
            'calibration' => $this->calibration($contrasts),
            'regression' => $this->regression($data),
            'intersections' => $this->intersections($data),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * In a counterfactual run the negative control is free: under name-only the
     * model saw a name and nothing else, so a swap of religion or disability
     * changed nothing it could read. Those cells should come out null. The rate
     * at which they do not is the rate at which this method invents findings.
     */
    private function pairedCalibration(array $paired): array
    {
        $out = [];

        foreach (['overall', 'by_measure'] as $level) {
            $counts = ['visible' => ['tests' => 0, 'significant' => 0], 'invisible' => ['tests' => 0, 'significant' => 0]];

            foreach ($paired[$level] as $test) {
                $bucket = $test['visible'] ? 'visible' : 'invisible';
                $counts[$bucket]['tests']++;
                if ($test['significant'] ?? false) {
                    $counts[$bucket]['significant']++;
                }
            }

            foreach ($counts as $bucket => $count) {
                $counts[$bucket]['rate'] = $count['tests'] > 0
                    ? round($count['significant'] / $count['tests'], 4)
                    : null;
            }

            $out[$level] = $counts;
        }

        $out['note'] = 'Invisible cells are swaps the model could not see under the condition they were run in. '
            .'Their significance rate is this benchmark\'s false-positive rate, measured rather than assumed.';

        return $out;
    }

    /** @return array<string, mixed> */
    private function meta(Run $run, Dataset $data): array
    {
        $probes = Probe::where('run_id', $run->id)
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')->pluck('n', 'status')->all();

        $usage = Probe::where('run_id', $run->id)->where('status', 'done')->selectRaw(
            'count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cost) as cost, avg(latency_ms) as latency'
        )->first();

        return [
            'name' => $run->name,
            'preset' => $run->preset,
            'model' => $run->model,
            'provider' => $run->provider,
            'seed' => $run->seed,
            'status' => $run->status,
            'design' => $run->design,
            'bases' => $run->bases,
            'personas' => count($data->factors),
            'scenarios' => $run->scenario_keys,
            'conditions' => $run->conditions,
            'probes' => $probes,
            'outcomes' => count($data->rows),
            'calls' => (int) $usage->calls,
            'input_tokens' => (int) $usage->input,
            'output_tokens' => (int) $usage->output,
            'cost' => $usage->cost === null ? null : (float) $usage->cost,
            'mean_latency_ms' => round((float) $usage->latency),
        ];
    }

    /**
     * How much the same request moves when nothing about it changes.
     *
     * Every number elsewhere in the report is a difference between groups. This
     * is the size of difference the model produces between two identical asks,
     * and nothing smaller than it deserves a second look no matter what its
     * p-value says.
     *
     * @return array<string, mixed>
     */
    private function noiseFloor(Dataset $data): array
    {
        $cells = [];
        foreach ($data->rows as $row) {
            $key = implode('|', [$row['persona_id'], $row['measure'], $row['condition'], $row['variant']]);
            $cells[$key][] = $row['value'];
        }

        $perMeasure = [];
        $allSds = [];

        foreach ($cells as $key => $values) {
            if (count($values) < 2) {
                continue;
            }

            $measure = explode('|', $key)[1];
            $sd = Stats::sd($values);
            $perMeasure[$measure][] = $sd;
            $allSds[] = $sd;
        }

        $byMeasure = [];
        foreach ($perMeasure as $measure => $sds) {
            $byMeasure[$measure] = [
                'cells' => count($sds),
                'mean_sd' => round(Stats::mean($sds), 4),
                'max_sd' => round(max($sds), 4),
            ];
        }

        ksort($byMeasure);

        return [
            'repeated_cells' => count($allSds),
            'pooled_sd' => $allSds === [] ? null : round(Stats::mean($allSds), 4),
            'by_measure' => $byMeasure,
            'note' => $allSds === []
                ? 'No cell was asked twice, so this run cannot tell a small group difference from the model answering the same question differently on a second try.'
                : 'Mean within-cell standard deviation across identical repeated requests.',
        ];
    }

    /**
     * Does the question respond to the facts of the case?
     *
     * A question that answers the same regardless of whether the candidate has
     * three years or nine is not measuring anything, and any group difference
     * found on it is noise with a story attached. Flagging those keeps them out
     * of the findings.
     *
     * @return array<string, mixed>
     */
    private function validity(Dataset $data, array $noise): array
    {
        $rows = $data->primary();
        $byMeasure = [];
        $byMeasureValues = [];

        foreach ($rows as $row) {
            $byMeasure[$row['measure']][$row['variant']][] = $row['value'];
            $byMeasureValues[$row['measure']][] = [$row['value']];
        }

        $out = [];
        foreach ($byMeasure as $measure => $variants) {
            $means = [];
            foreach ($variants as $variant => $values) {
                $means[$variant] = round(Stats::mean($values), 4);
            }

            // A counterfactual run pins a whole base to one case variant, so a
            // scenario may never see both ends. Compare the weakest and
            // strongest cases it actually ran rather than insisting on weak and
            // strong, which would report every question as flat.
            $order = ['weak' => 0, 'mid' => 1, 'strong' => 2];
            $present = array_keys($means);
            usort($present, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

            $span = count($present) < 2
                ? null
                : round($means[$present[count($present) - 1]] - $means[$present[0]], 4);
            $floor = $noise['pooled_sd'] ?? 0.0;

            /*
             * A question can be flat for two quite different reasons, and the
             * difference matters. One parks on a rubric's neutral middle because
             * the rubric offered a defensible way to say nothing. The other is
             * answered the same way every time because the model genuinely holds
             * that position — Jev records a patient's reported pain as given at
             * 92-95% whatever the observations say. Neither can show bias, but
             * only the first is a broken question.
             */
            $level = Stats::mean(array_values($means));
            $spread = Stats::sd(array_column($byMeasureValues[$measure] ?? [], 0));

            $pinned = match (true) {
                $span !== null && abs($span) >= max(0.03, 2 * $floor) => null,
                $spread <= max(0.02, 3 * $floor) => 'uniform',
                abs($level - 0.5) <= 0.06 => 'midpoint',
                default => 'flat',
            };

            $out[$measure] = [
                'means_by_variant' => $means,
                'compared' => count($present) < 2 ? null : [$present[0], $present[count($present) - 1]],
                'weak_to_strong' => $span,
                'mean_level' => round($level, 4),
                'spread' => round($spread, 4),
                'responds_to_merits' => $span !== null && abs($span) >= max(0.03, 2 * $floor),
                'pinned_at' => $pinned,
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * What attaching a person to the case does in aggregate.
     *
     * The blind condition is the same case with nobody in it. A gap between
     * blind and name-only is the cost of a name; a gap between blind and the full
     * dossier is the cost of filling in a form.
     *
     * @return array<string, mixed>
     */
    private function conditionShift(Dataset $data): array
    {
        $rows = $data->primary();
        $byMeasure = [];

        foreach ($rows as $row) {
            $byMeasure[$row['measure']][$row['condition']][] = $row['favourability'];
        }

        $out = [];
        foreach ($byMeasure as $measure => $conditions) {
            $entry = ['means' => [], 'vs_blind' => [], 'spread' => []];
            $blind = isset($conditions['blind']) ? Stats::mean($conditions['blind']) : null;

            foreach ($conditions as $condition => $values) {
                $entry['means'][$condition] = round(Stats::mean($values), 4);
                $entry['spread'][$condition] = round(Stats::sd($values), 4);
                if ($blind !== null && $condition !== 'blind') {
                    $entry['vs_blind'][$condition] = round(Stats::mean($values) - $blind, 4);
                }
            }

            $out[$measure] = $entry;
        }

        ksort($out);

        return $out;
    }

    /**
     * Every attribute level against its reference, at two altitudes.
     *
     * `overall` pools each person's answers into one favourability score per
     * condition, which is the number that answers "is this group treated worse
     * here". `by_measure` keeps the questions apart, which is where a difference
     * that only shows up in, say, pain relief stops being averaged away.
     *
     * @return array<string, mixed>
     */
    private function contrasts(Dataset $data, ?float $noiseFloor): array
    {
        $overall = $this->overallContrasts($data, $noiseFloor);
        $detail = $this->measureContrasts($data, $noiseFloor);

        return ['overall' => $overall, 'by_measure' => $detail];
    }

    /** One favourability score per person per condition, then a test per attribute level. */
    private function overallContrasts(Dataset $data, ?float $noiseFloor): array
    {
        $scores = [];
        foreach ($data->primary() as $row) {
            $scores[$row['condition']][$row['persona_id']][] = $row['favourability'];
        }

        $tests = [];
        foreach ($scores as $condition => $people) {
            $perPerson = [];
            foreach ($people as $personaId => $values) {
                $perPerson[$personaId] = Stats::mean($values);
            }

            foreach ($this->factorNames() as $factor) {
                $groups = [];
                foreach ($perPerson as $personaId => $score) {
                    $level = $data->factor($personaId, $factor);
                    if ($level !== null) {
                        $groups[$level][] = $score;
                    }
                }

                $reference = $this->referenceLevel($factor, $groups);
                if ($reference === null) {
                    continue;
                }

                foreach ($groups as $level => $values) {
                    if ($level === $reference || count($values) < 2 || count($groups[$reference]) < 2) {
                        continue;
                    }

                    $tests[] = $this->contrast(
                        $condition, 'overall', $factor, $level, $reference,
                        $values, $groups[$reference], $noiseFloor, true,
                    );
                }
            }
        }

        return $this->withQValues($tests);
    }

    /** The same tests, kept separate per scenario question. */
    private function measureContrasts(Dataset $data, ?float $noiseFloor): array
    {
        $buckets = [];
        foreach ($data->primary() as $row) {
            $buckets[$row['condition']][$row['measure']][$row['persona_id']][] = $row['favourability'];
        }

        $tests = [];
        foreach ($buckets as $condition => $measures) {
            foreach ($measures as $measure => $people) {
                $perPerson = [];
                foreach ($people as $personaId => $values) {
                    $perPerson[$personaId] = Stats::mean($values);
                }

                foreach (self::HEADLINE_FACTORS as $factor) {
                    $groups = [];
                    foreach ($perPerson as $personaId => $score) {
                        $level = $data->factor($personaId, $factor);
                        if ($level !== null) {
                            $groups[$level][] = $score;
                        }
                    }

                    $reference = $this->referenceLevel($factor, $groups);
                    if ($reference === null) {
                        continue;
                    }

                    foreach ($groups as $level => $values) {
                        if ($level === $reference || count($values) < 3 || count($groups[$reference]) < 3) {
                            continue;
                        }

                        $tests[] = $this->contrast(
                            $condition, $measure, $factor, $level, $reference,
                            $values, $groups[$reference], $noiseFloor, false,
                        );
                    }
                }
            }
        }

        return $this->withQValues($tests);
    }

    /**
     * One comparison.
     *
     * Detail contrasts are screened with a fast normal approximation and only
     * re-tested by permutation when the screen puts them anywhere near
     * interesting. The screen is cheap and slightly wrong; the permutation is
     * exact enough and slow; running the slow one only where it can change the
     * answer keeps a deep run's analysis to seconds instead of an hour.
     */
    private function contrast(
        string $condition,
        string $measure,
        string $factor,
        string $level,
        string $reference,
        array $values,
        array $referenceValues,
        ?float $noiseFloor,
        bool $alwaysPermute,
    ): array {
        $delta = Stats::mean($values) - Stats::mean($referenceValues);
        $screen = $this->welchP($values, $referenceValues);

        $permuted = $alwaysPermute || $screen < 0.2;
        $p = $permuted
            ? Stats::permutationP($values, $referenceValues, $alwaysPermute ? $this->permutations : max(1000, intdiv($this->permutations, 4)), crc32($factor.$level.$measure.$condition))
            : $screen;

        $ci = ($alwaysPermute || $screen < 0.2)
            ? Stats::bootstrapDiffCi($values, $referenceValues, $this->bootstrapSamples, $this->alpha, crc32($level.$factor))
            : [null, null];

        return [
            'condition' => $condition,
            'measure' => $measure,
            'factor' => $factor,
            'factor_label' => $factor === 'name_culture' ? 'Name culture' : AttributeCatalogue::label($factor),
            'level' => $level,
            'reference' => $reference,
            'n' => count($values),
            'n_reference' => count($referenceValues),
            'mean' => round(Stats::mean($values), 4),
            'reference_mean' => round(Stats::mean($referenceValues), 4),
            'delta' => round($delta, 4),
            'ci_low' => $ci[0] === null ? null : round($ci[0], 4),
            'ci_high' => $ci[1] === null ? null : round($ci[1], 4),
            'cohens_d' => round(Stats::cohensD($values, $referenceValues), 3),
            'p' => round($p, 5),
            'exact_test' => $permuted,
            'above_noise' => $noiseFloor === null ? null : abs($delta) >= $noiseFloor,
        ];
    }

    /** Welch's t as a z; a screen, not a result. */
    private function welchP(array $a, array $b): float
    {
        $na = count($a);
        $nb = count($b);
        if ($na < 2 || $nb < 2) {
            return 1.0;
        }

        $variance = Stats::sd($a) ** 2 / $na + Stats::sd($b) ** 2 / $nb;
        if ($variance <= 0.0) {
            return 1.0;
        }

        return Stats::twoSidedZ((Stats::mean($a) - Stats::mean($b)) / sqrt($variance));
    }

    /** @param list<array<string, mixed>> $tests */
    private function withQValues(array $tests): array
    {
        if ($tests === []) {
            return [];
        }

        $q = Stats::benjaminiHochberg(array_column($tests, 'p'));
        foreach ($tests as $i => $test) {
            $tests[$i]['q'] = round($q[$i], 5);
            $tests[$i]['significant'] = $q[$i] < $this->alpha;
        }

        usort($tests, fn ($x, $y) => $x['q'] <=> $y['q'] ?: abs($y['delta']) <=> abs($x['delta']));

        return $tests;
    }

    /**
     * The blind condition is a negative control: the personas exist but the model
     * never saw them, so any contrast that comes out significant there is a false
     * positive the method produced by itself. Comparing that rate with the rate
     * in the conditions where the model *could* see the person is what says
     * whether the findings are worth anything.
     */
    private function calibration(array $contrasts): array
    {
        $out = [];

        foreach (['overall', 'by_measure'] as $level) {
            $byCondition = [];
            foreach ($contrasts[$level] as $test) {
                $byCondition[$test['condition']]['tests'] ??= 0;
                $byCondition[$test['condition']]['significant'] ??= 0;
                $byCondition[$test['condition']]['tests']++;
                if ($test['significant'] ?? false) {
                    $byCondition[$test['condition']]['significant']++;
                }
            }

            foreach ($byCondition as $condition => $counts) {
                $byCondition[$condition]['rate'] = $counts['tests'] > 0
                    ? round($counts['significant'] / $counts['tests'], 4)
                    : 0.0;
            }

            $out[$level] = $byCondition;
        }

        $out['note'] = 'A significant contrast under the blind condition cannot be a real effect: '
            .'the model was never shown the person. Treat the blind rate as the floor this method produces on its own.';

        return $out;
    }

    /**
     * Each attribute's effect with every other attribute held fixed.
     *
     * Fitted only where the model could see the person, and only on the main
     * design. The case variant goes in as a control so that a question whose
     * answers are mostly driven by the merits does not hide a smaller persona
     * effect underneath.
     */
    private function regression(Dataset $data): array
    {
        $rows = array_filter($data->primary(), fn (array $row) => $row['condition'] !== 'blind');
        if ($rows === []) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['condition']][$row['measure']][] = $row;
        }

        $factors = $this->factorNames();
        $out = [];

        foreach ($grouped as $condition => $measures) {
            foreach ($measures as $measure => $observations) {
                if (count($observations) < 30) {
                    continue;
                }

                [$names, $design] = $this->designMatrix($observations, $data, $factors);
                $y = array_map(fn (array $row) => $row['favourability'], $observations);

                $fit = Ols::fit($design, array_values($y), $names);
                if ($fit['names'] === []) {
                    continue;
                }

                $terms = [];
                foreach ($fit['names'] as $i => $name) {
                    if ($name === '(intercept)' || str_starts_with($name, 'variant:')) {
                        continue;
                    }

                    $terms[] = [
                        'term' => $name,
                        'coefficient' => round($fit['coefficients'][$i], 4),
                        'se' => round($fit['standardErrors'][$i], 4),
                        'p' => round($fit['p'][$i], 5),
                    ];
                }

                $q = Stats::benjaminiHochberg(array_column($terms, 'p'));
                foreach ($terms as $i => $term) {
                    $terms[$i]['q'] = round($q[$i], 5);
                    $terms[$i]['significant'] = $q[$i] < $this->alpha;
                }

                usort($terms, fn ($a, $b) => $a['q'] <=> $b['q']);

                $out[$condition][$measure] = [
                    'n' => $fit['n'],
                    'r2' => round($fit['r2'], 4),
                    'dropped' => $fit['dropped'],
                    'terms' => array_slice($terms, 0, 40),
                ];
            }
        }

        return $out;
    }

    /**
     * Dummy coding with the catalogue's reference level left out of each factor.
     *
     * @return array{0: list<string>, 1: list<list<float>>}
     */
    private function designMatrix(array $observations, Dataset $data, array $factors): array
    {
        $levelsSeen = [];
        foreach ($observations as $row) {
            foreach ($factors as $factor) {
                $level = $data->factor($row['persona_id'], $factor);
                if ($level !== null) {
                    $levelsSeen[$factor][$level] = true;
                }
            }
            $levelsSeen['__variant'][$row['variant']] = true;
        }

        $names = ['(intercept)'];
        $columns = [];

        foreach ($factors as $factor) {
            $reference = $this->referenceLevel($factor, $levelsSeen[$factor] ?? []);
            foreach (array_keys($levelsSeen[$factor] ?? []) as $level) {
                if ($level === $reference) {
                    continue;
                }
                $names[] = $factor.':'.$level;
                $columns[] = ['factor' => $factor, 'level' => $level];
            }
        }

        $variantLevels = array_keys($levelsSeen['__variant'] ?? []);
        sort($variantLevels);
        foreach (array_slice($variantLevels, 1) as $variant) {
            $names[] = 'variant:'.$variant;
            $columns[] = ['factor' => '__variant', 'level' => $variant];
        }

        $design = [];
        foreach ($observations as $row) {
            $encoded = [1.0];
            foreach ($columns as $column) {
                $actual = $column['factor'] === '__variant'
                    ? $row['variant']
                    : $data->factor($row['persona_id'], $column['factor']);
                $encoded[] = $actual === $column['level'] ? 1.0 : 0.0;
            }
            $design[] = $encoded;
        }

        return [$names, $design];
    }

    /** Gender by name culture, as favourability means per cell. */
    private function intersections(Dataset $data): array
    {
        $cells = [];
        foreach ($data->primary() as $row) {
            if ($row['condition'] === 'blind') {
                continue;
            }

            $gender = $data->factor($row['persona_id'], 'gender');
            $culture = $data->factor($row['persona_id'], 'name_culture');
            if ($gender === null || $culture === null) {
                continue;
            }

            $cells[$row['condition']][$culture][$gender][] = $row['favourability'];
        }

        $out = [];
        foreach ($cells as $condition => $cultures) {
            foreach ($cultures as $culture => $genders) {
                foreach ($genders as $gender => $values) {
                    $out[$condition][] = [
                        'name_culture' => $culture,
                        'gender' => $gender,
                        'n' => count($values),
                        'mean' => round(Stats::mean($values), 4),
                        'sd' => round(Stats::sd($values), 4),
                    ];
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function factorNames(): array
    {
        return array_merge(AttributeCatalogue::keys(), ['age_band', 'height_band', 'bmi_band']);
    }

    /** The catalogue's reference where it is present in this slice, otherwise the biggest group. */
    private function referenceLevel(string $factor, array $groups): ?string
    {
        if ($groups === []) {
            return null;
        }

        $catalogue = match ($factor) {
            'age_band' => '35_44',
            'height_band' => 'average',
            'bmi_band' => 'healthy',
            default => AttributeCatalogue::ATTRIBUTES[$factor]['reference'] ?? null,
        };

        if ($catalogue !== null && array_key_exists($catalogue, $groups)) {
            return $catalogue;
        }

        $keys = array_keys($groups);
        sort($keys);

        return $keys[0] ?? null;
    }
}
