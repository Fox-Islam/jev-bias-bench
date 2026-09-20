<?php

declare(strict_types=1);

namespace App\Bench\Probing;

use App\Bench\Scenarios\ScenarioRegistry;
use App\Models\Outcome;
use App\Models\Person;
use App\Models\Probe;
use App\Models\Run;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Submits a whole run to OpenRouter's batch endpoint and collects it later.
 *
 * The same calls at half the price, in exchange for waiting. Worth it here
 * because nothing about a bias run is latency-sensitive: the questions are all
 * known before the first one is sent, and the answers are read days later by a
 * statistician rather than seconds later by a user.
 *
 * It does not compose with prompt caching. A cache entry lives about five
 * minutes, and the batch scheduler makes no promise about running a scenario's
 * requests together or soon, so the prefix that a synchronous run keeps warm is
 * cold by the time the next batched request reads it. The two discounts are
 * alternatives, and which one wins depends on the shape of the run.
 */
final class BatchRunner
{
    /** @var array<int, Person> */
    private array $people = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly OpenRouterProber $prober,
        private readonly ?Http $http = null,
    ) {}

    /** @return array{id: string, requests: int} */
    public function submit(Run $run, string $model, ?int $limit = null): array
    {
        $probes = Probe::where('run_id', $run->id)
            ->whereIn('status', ['pending', 'retry'])
            ->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        if ($probes->isEmpty()) {
            throw new RuntimeException('Nothing pending to submit.');
        }

        $people = Person::whereIn('id', $probes->pluck('persona_id')->unique())->get()->keyBy('id');
        $requests = [];

        foreach ($probes as $probe) {
            $scenario = ScenarioRegistry::get($probe->scenario_key);
            $persona = $people[$probe->persona_id]->persona();

            $requests[] = [
                'custom_id' => (string) $probe->id,
                'body' => $this->prober->batchBody(
                    $scenario,
                    $persona,
                    Condition::from($probe->condition),
                    $probe->case_variant,
                ),
            ];
        }

        // `endpoint` and `model` must precede `requests` — the API stream-parses
        // the body and rejects it otherwise.
        $payload = [
            'endpoint' => '/v1/chat/completions',
            'model' => $model,
            'completion_window' => '24h',
            'requests' => $requests,
        ];

        $response = $this->client()->post($this->url('/batches'), [
            'headers' => $this->headers(),
            'body' => json_encode($payload),
            'timeout' => 300,
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $id = $data['id'] ?? throw new RuntimeException('No batch id came back: '.substr(json_encode($data), 0, 300));

        DB::transaction(function () use ($run, $probes, $id) {
            Probe::whereIn('id', $probes->pluck('id'))->update(['status' => 'batched', 'claimed_at' => now()]);

            // A run of any size goes out as several batches, so the ids
            // accumulate rather than replace each other.
            $ids = $this->batchIds($run);
            $ids[] = (string) $id;
            $run->forceFill(['notes' => json_encode(['batches' => $ids]), 'status' => 'running'])->save();
        });

        return ['id' => (string) $id, 'requests' => count($requests)];
    }

    /** @return array<string, mixed> */
    public function status(string $id): array
    {
        $response = $this->client()->get($this->url('/batches/'.$id), [
            'headers' => $this->headers(),
            'timeout' => 120,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /** @return list<string> */
    public function batchIds(Run $run): array
    {
        $notes = json_decode((string) $run->notes, true);
        if (! is_array($notes)) {
            return [];
        }

        // Runs submitted before chunking carried a single id.
        return $notes['batches'] ?? (isset($notes['batch_id']) ? [$notes['batch_id']] : []);
    }

    public function pending(Run $run): int
    {
        return Probe::where('run_id', $run->id)->whereIn('status', ['pending', 'retry'])->count();
    }

    /**
     * Writes a finished batch back onto its probes.
     *
     * @return array{done: int, failed: int}
     */
    public function collect(Run $run, array $batch): array
    {
        $done = 0;
        $failed = 0;

        foreach ($batch['results'] ?? [] as $result) {
            $probe = Probe::find((int) ($result['custom_id'] ?? 0));
            if ($probe === null || $probe->run_id !== $run->id) {
                continue;
            }

            $body = $result['response']['body'] ?? null;
            $content = $body['choices'][0]['message']['content'] ?? null;
            $answers = is_string($content) ? json_decode($content, true) : null;

            if (! is_array($answers)) {
                $probe->forceFill([
                    'status' => 'failed',
                    'error' => 'Batch entry carried no usable answer: '.substr(json_encode($result['error'] ?? $result), 0, 500),
                ])->save();
                $failed++;

                continue;
            }

            $scenario = ScenarioRegistry::get($probe->scenario_key);
            $outcomes = $this->prober->read($scenario, $answers);
            $usage = $body['usage'] ?? [];

            // The batch reply does not echo what was asked, and a number nobody
            // can trace back to a payload is not evidence. It is rebuilt here
            // from the same seed-derived persona that produced it.
            $persona = ($this->people[$probe->persona_id] ??= Person::find($probe->persona_id))->persona();
            $request = $this->prober->batchBody(
                $scenario,
                $persona,
                Condition::from($probe->condition),
                $probe->case_variant,
            );

            DB::transaction(function () use ($probe, $outcomes, $usage, $answers, $body, $request) {
                $probe->forceFill([
                    'status' => 'done',
                    'error' => null,
                    'request_payload' => $request,
                    'response_payload' => ['answers' => $answers, 'usage' => $usage, 'model' => $body['model'] ?? null],
                    'request_id' => $body['id'] ?? null,
                    'input_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
                    'output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
                    'cost' => isset($usage['cost']) ? (float) $usage['cost'] : null,
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

            $done++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    private function client(): Http
    {
        return $this->http ?? new Http;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => 'https://github.com/Fox-Islam/jev-bias-bench',
            'X-Title' => 'Jev Bias Bench',
        ];
    }
}
