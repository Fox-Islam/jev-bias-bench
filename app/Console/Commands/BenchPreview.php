<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Personas\PersonaGenerator;
use App\Bench\Probing\Condition;
use App\Bench\Probing\StateBuilder;
use App\Bench\Scenarios\ScenarioRegistry;
use Illuminate\Console\Command;

class BenchPreview extends Command
{
    protected $signature = 'bench:preview
        {--scenario=hiring_screen : Which scenario}
        {--condition=full : blind, name_only, attributes_only or full}
        {--variant=mid : weak, mid or strong}
        {--n=2 : How many people to show}
        {--seed= : Cohort seed}';

    protected $description = 'Print the exact payload a probe would send, to eyeball before spending anything';

    public function handle(): int
    {
        $scenario = ScenarioRegistry::get((string) $this->option('scenario'));
        $condition = Condition::from((string) $this->option('condition'));
        $variant = (string) $this->option('variant');
        $seed = (int) ($this->option('seed') ?: config('bench.seed'));
        $builder = new StateBuilder;

        foreach ((new PersonaGenerator($seed))->cohort((int) $this->option('n')) as $persona) {
            $this->line('<info>'.$persona->fullName().'</info>');
            $this->line(json_encode([
                'state' => $builder->build($scenario, $persona, $condition, $variant),
                'model' => config('typesafe.model', 'jev-latest'),
                'questions' => $scenario->questionPayloads(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
