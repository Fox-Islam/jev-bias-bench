<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Probe;
use App\Models\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchStatus extends Command
{
    protected $signature = 'bench:status {--run= : A run name; defaults to the newest}';

    protected $description = 'Show what a run has cost and how far it has got';

    public function handle(): int
    {
        $run = $this->option('run')
            ? Run::where('name', $this->option('run'))->firstOrFail()
            : Run::latest('id')->first();

        if ($run === null) {
            $this->warn('No runs yet.');

            return self::SUCCESS;
        }

        $byStatus = Probe::where('run_id', $run->id)
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')->pluck('n', 'status')->all();

        $totals = Probe::where('run_id', $run->id)->where('status', 'done')->selectRaw(
            'count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cost) as cost, avg(latency_ms) as latency'
        )->first();

        $this->line("<info>{$run->name}</info>  preset={$run->preset}  model={$run->model}  provider={$run->provider}  seed={$run->seed}  status={$run->status}");
        $this->newLine();

        $this->table(['status', 'probes'], collect($byStatus)->map(fn ($n, $s) => [$s, $n])->values()->all());

        $this->table(['calls', 'input tokens', 'output tokens', 'cost', 'mean latency'], [[
            (int) $totals->calls,
            number_format((int) $totals->input),
            number_format((int) $totals->output),
            $totals->cost === null ? 'not reported' : '$'.number_format((float) $totals->cost, 4),
            round((float) $totals->latency).'ms',
        ]]);

        $failures = Probe::where('run_id', $run->id)->where('status', 'failed')->limit(3)->pluck('error');
        foreach ($failures as $error) {
            $this->warn(substr((string) $error, 0, 160));
        }

        return self::SUCCESS;
    }
}
