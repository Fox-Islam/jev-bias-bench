<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Probing\Prober;
use App\Bench\Probing\Worker;
use App\Models\Run;
use Illuminate\Console\Command;
use Phox\TypeSafe\Client;

class BenchWork extends Command
{
    protected $signature = 'bench:work
        {--run= : The run to drain}
        {--limit= : Stop after this many probes}
        {--quiet-progress : Do not print a line per probe}';

    protected $description = 'Drain pending probes for a run (one worker process)';

    public function handle(Client $client): int
    {
        $run = Run::where('name', $this->option('run'))->firstOrFail();

        $worker = new Worker(
            prober: new Prober($client),
            timeout: (float) config('bench.timeout'),
            maxAttempts: (int) config('bench.max_attempts'),
        );

        $quiet = (bool) $this->option('quiet-progress');

        $result = $worker->drain(
            run: $run,
            onProbe: $quiet ? null : function ($probe, bool $ok) {
                $this->line(sprintf(
                    '%s %s/%s/%s #%d %s',
                    $ok ? '<info>ok</info>' : '<error>!!</error>',
                    $probe->scenario_key,
                    $probe->condition,
                    $probe->case_variant,
                    $probe->persona_id,
                    $ok ? $probe->latency_ms.'ms' : substr((string) $probe->error, 0, 90),
                ));
            },
            limit: $this->option('limit') ? (int) $this->option('limit') : null,
        );

        if (! $quiet) {
            $this->info("Done {$result['done']}, failed {$result['failed']}.");
        }

        return self::SUCCESS;
    }
}
