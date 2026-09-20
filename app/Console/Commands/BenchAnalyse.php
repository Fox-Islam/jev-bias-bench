<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Analysis\ReportStore;
use App\Models\Run;
use Illuminate\Console\Command;

class BenchAnalyse extends Command
{
    protected $signature = 'bench:analyse
        {--run= : A run name; defaults to the newest}
        {--fresh : Recompute instead of reading the cached report}
        {--top=20 : How many contrasts to print}';

    protected $description = 'Work out what a run says about bias and print the headlines';

    public function handle(ReportStore $store): int
    {
        $run = $this->option('run')
            ? Run::where('name', $this->option('run'))->firstOrFail()
            : Run::latest('id')->firstOrFail();

        $this->line("Analysing <info>{$run->name}</info>...");
        $report = $store->get($run, (bool) $this->option('fresh'));

        $meta = $report['run'];
        $this->newLine();
        $this->line("{$meta['personas']} people, {$meta['outcomes']} answers from {$meta['calls']} calls, model {$meta['model']}.");

        $floor = $report['noise_floor'];
        $this->newLine();
        $this->line('<comment>Noise floor</comment> — the same request asked twice');
        $this->line($floor['pooled_sd'] === null
            ? '  no repeated cells in this run'
            : "  pooled within-cell SD {$floor['pooled_sd']} across {$floor['repeated_cells']} repeated cells");

        $insensitive = [];
        foreach ($report['validity'] as $measure => $check) {
            if (! $check['responds_to_merits']) {
                $insensitive[] = $measure;
            }
        }
        $this->newLine();
        $this->line('<comment>Validity</comment> — does the question move with the facts of the case');
        if ($insensitive === []) {
            $this->line('  every question responds to the merits');
        } else {
            foreach ($insensitive as $measure) {
                $check = $report['validity'][$measure];
                $why = match ($check['pinned_at'] ?? null) {
                    'uniform' => 'the same answer for everyone and every case (mean '.$check['mean_level'].', spread '.$check['spread'].') — no room for bias to show',
                    'midpoint' => '<error>parked on the neutral middle</error> ('.$check['mean_level'].') — the question is not making it choose',
                    default => 'does not move with the case (mean '.$check['mean_level'].', spread '.$check['spread'].')',
                };
                $this->line("  <comment>{$measure}</comment>: {$why}");
            }
        }

        isset($report['paired'])
            ? $this->paired($report)
            : $this->unpaired($report);

        $this->newLine();
        $this->line('Full report: <info>storage/app/private/'.$store->path($run).'</info>');

        return self::SUCCESS;
    }

    /** The counterfactual arm: each person against their own anchor. */
    private function paired(array $report): void
    {
        $paired = $report['paired'];

        $this->newLine();
        $this->line('<comment>Null distribution</comment> — differences between identical repeated requests');
        $this->line("  {$paired['null']['samples']} samples, SD {$paired['null']['sd']}, mean absolute difference {$paired['null']['mean_abs']}");

        $this->newLine();
        $this->line('<comment>Calibration</comment> — swaps the model could not see should come out null');
        $rows = [];
        foreach ($report['calibration']['overall'] as $bucket => $counts) {
            if (! is_array($counts)) {
                continue;
            }
            $rows[] = [$bucket, $counts['tests'], $counts['significant'], $counts['rate'] ?? '-'];
        }
        $this->table(['cells', 'tests', 'significant', 'rate'], $rows);

        $this->newLine();
        $this->line('<comment>Largest swaps</comment> — one attribute changed, everything else identical');
        $this->table(
            ['condition', 'attribute', 'swapped to', 'from', 'pairs', 'delta', 'sd', 'q', 'visible', 'above noise'],
            array_map(fn ($t) => [
                $t['condition'],
                $t['factor'],
                $t['level'],
                $t['reference'],
                $t['pairs'],
                sprintf('%+.3f', $t['delta']),
                sprintf('%.3f', $t['sd']),
                $t['q'],
                $t['visible'] ? 'yes' : '<error>no</error>',
                $t['above_noise'] === null ? '?' : ($t['above_noise'] ? 'yes' : 'no'),
            ], array_slice($paired['overall'], 0, (int) $this->option('top'))),
        );
    }

    /** The factorial arm: attribute levels against a reference across the cohort. */
    private function unpaired(array $report): void
    {
        $this->newLine();
        $this->line('<comment>Calibration</comment> — how often each condition produces a significant contrast');
        $rows = [];
        foreach ($report['calibration']['overall'] ?? [] as $condition => $counts) {
            if (! is_array($counts)) {
                continue;
            }
            $rows[] = [$condition, $counts['tests'], $counts['significant'], $counts['rate']];
        }
        $this->table(['condition', 'tests', 'significant', 'rate'], $rows);

        $this->newLine();
        $this->line('<comment>Largest contrasts</comment> — attribute level against its reference, in favourability');
        $top = array_slice($report['contrasts']['overall'], 0, (int) $this->option('top'));

        $this->table(
            ['condition', 'attribute', 'level', 'vs', 'n', 'delta', '95% CI', 'q', 'above noise'],
            array_map(fn ($t) => [
                $t['condition'],
                $t['factor'],
                $t['level'],
                $t['reference'],
                $t['n'],
                sprintf('%+.3f', $t['delta']),
                $t['ci_low'] === null ? '' : sprintf('%+.3f..%+.3f', $t['ci_low'], $t['ci_high']),
                $t['q'],
                $t['above_noise'] === null ? '?' : ($t['above_noise'] ? 'yes' : 'no'),
            ], $top),
        );
    }
}
