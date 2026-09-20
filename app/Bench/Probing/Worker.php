<?php

declare(strict_types=1);

namespace App\Bench\Probing;

use App\Bench\Scenarios\ScenarioRegistry;
use App\Models\Outcome;
use App\Models\Person;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Support\Facades\DB;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Throwable;

/**
 * Drains a run's pending probes.
 *
 * Work is claimed a row at a time with `FOR UPDATE SKIP LOCKED`, so several
 * workers can run against the same run without coordinating and without ever
 * handing the same probe to two of them. A worker that dies mid-call leaves its
 * probe claimed rather than lost — `bench:reset` returns stale claims to the
 * pool.
 */
final class Worker
{
    public function __construct(
        private readonly Prober $prober,
        private readonly float $timeout = 30.0,
        private readonly int $maxAttempts = 3,
    ) {}

    /**
     * @param  callable(Probe, bool): void|null  $onProbe
     * @return array{done: int, failed: int}
     */
    public function drain(Run $run, ?callable $onProbe = null, ?int $limit = null): array
    {
        $done = 0;
        $failed = 0;
        $people = [];

        while ($limit === null || $done + $failed < $limit) {
            $probe = $this->claim($run);
            if ($probe === null) {
                break;
            }

            $people[$probe->persona_id] ??= Person::find($probe->persona_id);
            $ok = $this->execute($probe, $people[$probe->persona_id], $run);
            $ok ? $done++ : $failed++;

            if ($onProbe !== null) {
                $onProbe($probe, $ok);
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    private function claim(Run $run): ?Probe
    {
        return DB::transaction(function () use ($run) {
            $probe = Probe::where('run_id', $run->id)
                ->whereIn('status', ['pending', 'retry'])
                ->where('attempts', '<', $this->maxAttempts)
                ->orderBy('id')
                ->lock('for update skip locked')   // Postgres: hand the row to exactly one worker and never block the others
                ->first();

            if ($probe === null) {
                return null;
            }

            $probe->forceFill([
                'status' => 'running',
                'claimed_at' => now(),
                'attempts' => $probe->attempts + 1,
            ])->save();

            return $probe;
        });
    }

    private function execute(Probe $probe, Person $person, Run $run): bool
    {
        $scenario = ScenarioRegistry::get($probe->scenario_key);
        $persona = $person->persona();
        $condition = Condition::from($probe->condition);
        $started = hrtime(true);

        try {
            $response = $this->prober->send($scenario, $persona, $condition, $probe->case_variant, $run->model, $this->timeout);
        } catch (TypeSafeException|Throwable $e) {
            $probe->forceFill([
                'status' => $probe->attempts >= $this->maxAttempts ? 'failed' : 'retry',
                'error' => substr($e::class.': '.$e->getMessage(), 0, 2000),
                'latency_ms' => (int) ((hrtime(true) - $started) / 1e6),
            ])->save();

            return false;
        }

        $outcomes = $this->prober->read($scenario, $response);

        DB::transaction(function () use ($probe, $response, $outcomes, $started, $scenario, $persona, $condition, $run) {
            $probe->forceFill([
                'status' => 'done',
                'error' => null,
                'request_payload' => $this->prober->request($scenario, $persona, $condition, $probe->case_variant, $run->model),
                'response_payload' => $response->toArray(),
                'request_id' => $response->requestId(),
                'latency_ms' => (int) ((hrtime(true) - $started) / 1e6),
                'input_tokens' => $response->usage()->inputTokens(),
                'output_tokens' => $response->usage()->outputTokens(),
                'cost' => $response->usage()->cost(),
                'completed_at' => now(),
            ])->save();

            Outcome::where('probe_id', $probe->id)->delete();

            $rows = [];
            foreach ($outcomes as $outcome) {
                $rows[] = [
                    'run_id' => $probe->run_id,
                    'probe_id' => $probe->id,
                    'persona_id' => $probe->persona_id,
                    'scenario_key' => $probe->scenario_key,
                    'condition' => $probe->condition,
                    'replicate' => $probe->replicate,
                    'case_variant' => $probe->case_variant,
                    'question_key' => $outcome->questionKey,
                    'kind' => $outcome->kind,
                    'value' => $outcome->value,
                    'confidence' => $outcome->confidence,
                    'raw' => json_encode($outcome->raw),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                Outcome::insert($rows);
            }
        });

        return true;
    }
}
