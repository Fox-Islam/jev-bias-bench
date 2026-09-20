<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Probe;
use App\Models\Run;
use Illuminate\Console\Command;

class BenchReset extends Command
{
    protected $signature = 'bench:reset
        {--run= : The run to reset}
        {--stale=15 : Minutes after which a claimed probe counts as abandoned}
        {--failed : Also return permanently failed probes to the queue}';

    protected $description = 'Return abandoned or failed probes to the pending queue';

    public function handle(): int
    {
        $run = Run::where('name', $this->option('run'))->firstOrFail();
        $cutoff = now()->subMinutes((int) $this->option('stale'));

        $stale = Probe::where('run_id', $run->id)
            ->where('status', 'running')
            ->where('claimed_at', '<', $cutoff)
            ->update(['status' => 'pending', 'claimed_at' => null]);

        $failed = 0;
        if ($this->option('failed')) {
            $failed = Probe::where('run_id', $run->id)
                ->where('status', 'failed')
                ->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'claimed_at' => null]);
        }

        $this->info("Requeued {$stale} abandoned and {$failed} failed probes.");

        return self::SUCCESS;
    }
}
