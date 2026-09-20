<?php

declare(strict_types=1);

namespace App\Bench\Analysis;

use App\Models\Run;
use Illuminate\Support\Facades\Storage;

/** Reports are expensive to compute and never change once a run is finished, so they are kept on disk. */
final class ReportStore
{
    public function __construct(private readonly Analyser $analyser) {}

    public function path(Run $run): string
    {
        return 'reports/'.$run->name.'.json';
    }

    public function has(Run $run): bool
    {
        return Storage::disk('local')->exists($this->path($run));
    }

    /** @return array<string, mixed> */
    public function get(Run $run, bool $fresh = false): array
    {
        if (! $fresh && $this->has($run)) {
            return json_decode(Storage::disk('local')->get($this->path($run)), true);
        }

        return $this->put($run);
    }

    /** @return array<string, mixed> */
    public function put(Run $run): array
    {
        $report = $this->analyser->report($run);
        Storage::disk('local')->put($this->path($run), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report;
    }
}
