<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Bench\Analysis\ReportStore;
use App\Models\Person;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The dashboard's whole API: the list of runs, a run's report, and the raw calls behind it. */
class ReportController extends Controller
{
    public function runs(): JsonResponse
    {
        return response()->json(
            Run::latest('id')->get()->map(fn (Run $run) => [
                'name' => $run->name,
                'preset' => $run->preset,
                'design' => $run->design,
                'model' => $run->model,
                'status' => $run->status,
                'personas' => $run->persona_count,
                'scenarios' => $run->scenario_keys,
                'conditions' => $run->conditions,
                'probes' => $run->probes()->count(),
                'done' => $run->probes()->where('status', 'done')->count(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])
        );
    }

    public function report(string $name, ReportStore $store): JsonResponse
    {
        $run = Run::where('name', $name)->firstOrFail();

        return response()->json($store->get($run));
    }

    /**
     * The calls behind a cell, so a number in the table can be traced to the
     * exact payload that produced it. A benchmark nobody can check is a rumour.
     */
    public function probes(string $name, Request $request): JsonResponse
    {
        $run = Run::where('name', $name)->firstOrFail();

        $probes = Probe::where('run_id', $run->id)
            ->when($request->query('scenario'), fn ($q, $v) => $q->where('scenario_key', $v))
            ->when($request->query('condition'), fn ($q, $v) => $q->where('condition', $v))
            ->when($request->query('attribute'), fn ($q, $v) => $q->whereIn(
                'persona_id',
                Person::where('run_id', $run->id)->where('swapped_attribute', $v)->select('id')
            ))
            ->when($request->query('level'), fn ($q, $v) => $q->whereIn(
                'persona_id',
                Person::where('run_id', $run->id)->where('swapped_level', $v)->select('id')
            ))
            ->where('status', 'done')
            ->with('person:id,full_name,base_index,is_anchor,swapped_attribute,swapped_level')
            ->orderBy('id')
            ->limit((int) $request->query('limit', 12))
            ->get();

        return response()->json($probes->map(fn (Probe $probe) => [
            'id' => $probe->id,
            'person' => $probe->person?->full_name,
            'base' => $probe->person?->base_index,
            'anchor' => (bool) $probe->person?->is_anchor,
            'swapped' => $probe->person?->swapped_attribute,
            'level' => $probe->person?->swapped_level,
            'scenario' => $probe->scenario_key,
            'condition' => $probe->condition,
            'variant' => $probe->case_variant,
            'replicate' => $probe->replicate,
            'latency_ms' => $probe->latency_ms,
            'request' => $probe->request_payload,
            'response' => $probe->response_payload,
        ]));
    }

    /** The people themselves, for reading a dossier next to the numbers it produced. */
    public function people(string $name): JsonResponse
    {
        $run = Run::where('name', $name)->firstOrFail();

        return response()->json(
            Person::where('run_id', $run->id)->orderBy('idx')->get()->map(fn (Person $person) => [
                'idx' => $person->idx,
                'name' => $person->full_name,
                'base' => $person->base_index,
                'anchor' => (bool) $person->is_anchor,
                'swapped' => $person->swapped_attribute,
                'level' => $person->swapped_level,
                'profile' => $person->profile,
            ])
        );
    }
}
