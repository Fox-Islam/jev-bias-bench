<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Probing\BatchRunner;
use App\Bench\Probing\OpenRouterProber;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Console\Command;

class BenchBatch extends Command
{
    protected $signature = 'bench:batch
        {action=submit : submit, status or collect}
        {--run= : The run to work on}
        {--chunk=500 : How many probes go in each batch}
        {--wait : Poll until the batch finishes, then collect it}';

    protected $description = 'Send a run through OpenRouter\'s batch endpoint at half price, and collect it';

    public function handle(): int
    {
        $run = Run::where('name', $this->option('run'))->firstOrFail();
        $runner = new BatchRunner(
            apiKey: (string) config('bench.openrouter.key'),
            baseUrl: (string) config('bench.openrouter.base_url'),
            prober: new OpenRouterProber(
                apiKey: (string) config('bench.openrouter.key'),
                baseUrl: (string) config('bench.openrouter.base_url'),
                reasoningEffort: config('bench.openrouter.reasoning_effort'),
            ),
        );

        return match ((string) $this->argument('action')) {
            'submit' => $this->submit($runner, $run),
            'status' => $this->status($runner, $run),
            'collect' => $this->collect($runner, $run),
            default => $this->fail('Unknown action.'),
        };
    }

    private function submit(BatchRunner $runner, Run $run): int
    {
        $model = str_ends_with($run->model, ':batch') ? $run->model : $run->model.':batch';
        $chunk = max(1, (int) $this->option('chunk'));
        $sent = 0;

        while ($runner->pending($run) > 0) {
            $result = $runner->submit($run, $model, $chunk);
            $sent += $result['requests'];
            $this->line("  {$result['id']}  {$result['requests']} requests  ({$sent} sent, {$runner->pending($run)} left)");
            $run->refresh();
        }

        $this->info("Submitted {$sent} requests across ".count($runner->batchIds($run))." batches on {$model}.");

        if (! $this->option('wait')) {
            $this->line('Check it with <info>bench:batch status --run='.$run->name.'</info>');

            return self::SUCCESS;
        }

        return $this->status($runner, $run);
    }

    private function status(BatchRunner $runner, Run $run): int
    {
        $ids = $runner->batchIds($run);
        if ($ids === []) {
            $this->error('That run has no batches on it.');

            return self::FAILURE;
        }

        do {
            $done = 0;
            $total = 0;
            $failed = 0;
            $settled = 0;
            $cost = 0.0;

            foreach ($ids as $id) {
                $batch = $runner->status($id);
                $counts = $batch['request_counts'] ?? [];
                $done += $counts['completed'] ?? 0;
                $total += $counts['total'] ?? 0;
                $failed += $counts['failed'] ?? 0;
                $cost += (float) ($batch['usage']['cost'] ?? 0);

                if (in_array($batch['status'] ?? '', ['completed', 'failed', 'expired', 'cancelled'], true)) {
                    $settled++;
                }
            }

            $this->line(sprintf(
                '%s  %d/%d batches settled  %d/%d answers, %d failed  $%s so far',
                now()->format('H:i:s'), $settled, count($ids), $done, $total, $failed, number_format($cost, 4),
            ));

            $finished = $settled === count($ids);
            if (! $finished && $this->option('wait')) {
                sleep(30);
            }
        } while (! $finished && $this->option('wait'));

        return $finished && $this->option('wait') ? $this->collect($runner, $run) : self::SUCCESS;
    }

    private function collect(BatchRunner $runner, Run $run): int
    {
        $ids = $runner->batchIds($run);
        if ($ids === []) {
            $this->error('That run has no batches on it.');

            return self::FAILURE;
        }

        $done = 0;
        $failed = 0;
        $cost = 0.0;

        foreach ($ids as $id) {
            $batch = $runner->status($id);
            if (($batch['status'] ?? '') !== 'completed') {
                $this->warn("{$id} is ".($batch['status'] ?? '?').'; skipped.');

                continue;
            }

            $result = $runner->collect($run, $batch);
            $done += $result['done'];
            $failed += $result['failed'];
            $cost += (float) ($batch['usage']['cost'] ?? 0);
        }

        $this->info("Collected {$done} answers, {$failed} failures, \$".number_format($cost, 4).' billed.');

        $left = Probe::where('run_id', $run->id)->whereIn('status', ['pending', 'retry', 'batched'])->count();
        $run->forceFill([
            'status' => $left > 0 ? 'running' : 'completed',
            'finished_at' => $left > 0 ? null : now(),
        ])->save();

        $this->call('bench:status', ['--run' => $run->name]);

        return self::SUCCESS;
    }
}
