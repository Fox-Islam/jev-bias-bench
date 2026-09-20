<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Scenarios\ScenarioRegistry;
use Illuminate\Console\Command;

class BenchExportVariants extends Command
{
    protected $signature = 'bench:export-variants {--out=local/variants.json}';

    protected $description = 'Dump the case variants and answer directions, so the mock server can imitate a consistently biased model';

    public function handle(): int
    {
        $out = ['variants' => [], 'directions' => []];
        foreach (ScenarioRegistry::all() as $scenario) {
            $out['variants'][$scenario->key] = $scenario->variants;
            foreach ($scenario->questions as $question) {
                $out['directions'][$question->key] = $question->favourable;
            }
        }

        $path = base_path((string) $this->option('out'));
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }
}
