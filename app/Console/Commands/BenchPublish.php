<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Analysis\ReportStore;
use App\Models\Run;
use Illuminate\Console\Command;

/**
 * Writes the dashboard out as one static file.
 *
 * GitHub Pages cannot run the Laravel side, and a findings page nobody can open
 * without Docker is a findings page nobody opens. This inlines the run's own
 * report artefact into a single self-contained document — same tables, same
 * numbers, no build step, no network. The raw-calls inspector is left out: it
 * exists to trace a figure back to the payload that produced it, and the
 * payloads stay in the repository rather than in a page.
 */
class BenchPublish extends Command
{
    protected $signature = 'bench:publish
        {--run= : The run to publish; defaults to the newest}
        {--out=docs/index.html : Where to write it}
        {--title= : Page title; defaults to the app name}';

    protected $description = 'Write a run up as a single static page for GitHub Pages';

    public function handle(ReportStore $store): int
    {
        $run = $this->option('run')
            ? Run::where('name', $this->option('run'))->firstOrFail()
            : Run::latest('id')->firstOrFail();

        $report = $this->trim($store->get($run));
        $stub = file_get_contents(resource_path('stubs/pages.html'));

        $html = str_replace(
            ['__TITLE__', '__REPORT__'],
            [
                e((string) ($this->option('title') ?: config('app.name'))),
                // Into a <script type="application/json"> block, so the only
                // sequence that could end it early is the closing tag itself.
                str_replace('</', '<\/', json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ],
            $stub,
        );

        $path = base_path((string) $this->option('out'));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, $html);

        $this->info(sprintf(
            'Wrote %s (%s KB) from run [%s].',
            $this->option('out'),
            number_format(strlen($html) / 1024),
            $run->name,
        ));

        return self::SUCCESS;
    }

    /**
     * Keeps only what the page draws.
     *
     * Chiefly this drops the per-question rows for control comparisons, which
     * the page never shows and which are a third of the file.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function trim(array $report): array
    {
        $paired = $report['paired'] ?? [];

        return [
            'run' => $report['run'],
            'design' => $report['design'] ?? null,
            'noise_floor' => ['pooled_sd' => $report['noise_floor']['pooled_sd'] ?? null],
            'validity' => $report['validity'],
            'conditions' => $report['conditions'],
            'calibration' => $report['calibration'] ?? null,
            'paired' => [
                'null' => $paired['null'] ?? null,
                'overall' => array_map($this->row(...), $paired['overall'] ?? []),
                'by_measure' => array_values(array_map(
                    $this->row(...),
                    array_filter($paired['by_measure'] ?? [], fn (array $t) => $t['visible'] !== false),
                )),
            ],
            'generated_at' => $report['generated_at'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function row(array $test): array
    {
        return [
            'condition' => $test['condition'],
            'measure' => $test['measure'],
            'factor' => $test['factor'],
            'factor_label' => $test['factor_label'],
            'level' => $test['level'],
            'reference' => $test['reference'],
            'pairs' => $test['pairs'] ?? $test['n'] ?? null,
            'delta' => $test['delta'],
            'q' => $test['q'],
            'significant' => $test['significant'] ?? false,
            'visible' => $test['visible'] ?? true,
        ];
    }
}
