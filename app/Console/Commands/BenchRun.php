<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Probing\Planner;
use App\Bench\Probing\RunProfile;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BenchRun extends Command
{
    protected $signature = 'bench:run
        {--profile=pilot : smoke, pilot, standard or deep}
        {--name= : A name for the run; defaults to the profile and a timestamp}
        {--seed= : Overrides the cohort seed}
        {--workers= : How many worker processes to drain the queue}
        {--scenarios= : Comma-separated scenario keys, replacing the ones the profile names}
        {--bases= : How many anchor people, replacing the number the profile names}
        {--swept= : Comma-separated attributes to sweep, replacing the set the profile names}
        {--anchor-reference : Put every unswept attribute at its reference too, so a narrowed run matches a full one}
        {--resume= : Carry on an existing run by name instead of planning a new one}
        {--plan-only : Write the design out and stop without calling the API}';

    protected $description = 'Plan a bias benchmark run and put every probe to Jev';

    public function handle(Planner $planner): int
    {
        $run = $this->option('resume')
            ? Run::where('name', $this->option('resume'))->firstOrFail()
            : null;

        if ($run === null) {
            $profile = RunProfile::named((string) $this->option('profile'))
                ->with(
                    scenarios: $this->option('scenarios')
                        ? array_map('trim', explode(',', (string) $this->option('scenarios')))
                        : null,
                    bases: $this->option('bases') ? (int) $this->option('bases') : null,
                    swept: $this->option('swept')
                        ? array_map('trim', explode(',', (string) $this->option('swept')))
                        : null,
                    anchorAtReference: (bool) $this->option('anchor-reference'),
                );
            $name = (string) ($this->option('name') ?: $profile->name.'-'.now()->format('Ymd-His'));
            $seed = (int) ($this->option('seed') ?: config('bench.seed'));

            $this->line("Planning <info>{$name}</info>: {$profile->personas} people, "
                .count($profile->scenarioKeys).' scenarios, '
                .count($profile->conditions).' conditions, '
                ."<comment>{$profile->probeCount()}</comment> calls.");

            $run = $planner->plan(
                name: $name,
                profile: $profile,
                seed: $seed,
                provider: (string) config('typesafe.provider', 'typesafe'),
                model: (string) config('typesafe.model', 'jev-latest'),
            );
        }

        if ($this->option('plan-only')) {
            $this->info("Planned {$run->probes()->count()} probes for run [{$run->name}]. Nothing sent.");

            return self::SUCCESS;
        }

        if (! $this->hasKey()) {
            $this->error('No API key. Set TYPESAFE_API_KEY (or OPENROUTER_API_KEY) in .env, then rerun with --resume='.$run->name);

            return self::FAILURE;
        }

        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();

        $workers = (int) ($this->option('workers') ?: config('bench.concurrency'));
        $pending = Probe::where('run_id', $run->id)->whereIn('status', ['pending', 'retry'])->count();

        $this->line("Draining <comment>{$pending}</comment> probes with {$workers} workers.");
        $bar = $this->output->createProgressBar($pending);
        $bar->start();

        $pool = Process::pool(function ($pool) use ($workers, $run) {
            for ($i = 0; $i < $workers; $i++) {
                $pool->path(base_path())
                    ->timeout(60 * 60 * 6)
                    ->command(['php', 'artisan', 'bench:work', '--run='.$run->name, '--quiet-progress']);
            }
        })->start();

        while ($pool->running()->isNotEmpty()) {
            $bar->setProgress(max(0, $pending - Probe::where('run_id', $run->id)->whereIn('status', ['pending', 'retry', 'running'])->count()));
            usleep(400_000);
        }

        $results = $pool->wait();
        $bar->finish();
        $this->newLine(2);

        foreach ($results as $index => $result) {
            if (! $result->successful()) {
                $detail = trim($result->errorOutput()) ?: trim($result->output());
                $this->warn("Worker {$index} exited {$result->exitCode()}: ".substr($detail, -600));
            }
        }

        $failed = Probe::where('run_id', $run->id)->where('status', 'failed')->count();
        $run->forceFill(['status' => $failed > 0 ? 'completed_with_errors' : 'completed', 'finished_at' => now()])->save();

        $this->call('bench:status', ['--run' => $run->name]);

        return self::SUCCESS;
    }

    private function hasKey(): bool
    {
        $provider = (string) config('typesafe.provider', 'typesafe');
        $key = $provider === 'openrouter'
            ? (config('typesafe.keys.openrouter') ?: env('OPENROUTER_API_KEY'))
            : (config('typesafe.keys.typesafe') ?: env('TYPESAFE_API_KEY'));

        return is_string($key) && trim($key) !== '';
    }
}
