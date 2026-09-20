<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Personas\AttributeCatalogue;
use App\Bench\Personas\OrthogonalArray;
use App\Bench\Probing\RunProfile;
use Illuminate\Console\Command;

class BenchDesign extends Command
{
    protected $signature = 'bench:design
        {--profile= : Describe one profile instead of all of them}
        {--compare= : Cohort size to compare an orthogonal layout against independent shuffles}';

    protected $description = 'Show what each run profile costs, and how well the factorial layout crosses its attributes';

    public function handle(): int
    {
        if ($size = $this->option('compare')) {
            return $this->compare((int) $size);
        }

        $names = $this->option('profile') ? [(string) $this->option('profile')] : RunProfile::names();
        $rows = [];

        foreach ($names as $name) {
            $profile = RunProfile::named($name);
            $rows[] = [
                $profile->name,
                $profile->design,
                $profile->design === 'counterfactual' ? $profile->bases : '-',
                $profile->personas,
                count($profile->scenarioKeys),
                count($profile->conditions),
                $profile->replicates,
                number_format($profile->probeCount()),
            ];
        }

        $this->table(['profile', 'design', 'bases', 'people', 'scenarios', 'conditions', 'reps', 'calls'], $rows);

        $swept = AttributeCatalogue::sweepable();
        $levels = 0;
        foreach ($swept as $attribute) {
            $levels += count(AttributeCatalogue::levels($attribute));
        }

        $this->newLine();
        $this->line(sprintf(
            '%d attributes, %d levels between them. A counterfactual base costs %d people; a factorial cohort costs whatever it is given.',
            count($swept), $levels, $levels - count($swept) + 1,
        ));

        return self::SUCCESS;
    }

    /**
     * Orthogonality is what an array buys, so measure it: the worst pair of
     * attributes, as how far its joint counts sit from perfectly crossed.
     */
    private function compare(int $size): int
    {
        $attributes = AttributeCatalogue::keys();
        $levelCounts = array_map(fn ($a) => count(AttributeCatalogue::levels($a)), $attributes);

        $this->line("Cohort of {$size}, ".count($attributes).' attributes.');
        $this->newLine();

        $rows = [];

        $random = OrthogonalArray::build($levelCounts, $size, 4242, iterations: 0);
        $rows[] = ['independent balanced shuffles', $random['imbalance'], $random['worst_pair'], $this->emptyCells($random['rows'], $levelCounts)];

        foreach ([20_000, 200_000] as $iterations) {
            $found = OrthogonalArray::build($levelCounts, $size, 4242, iterations: $iterations);
            $rows[] = ["orthogonal search, {$iterations} swaps", $found['imbalance'], $found['worst_pair'], $this->emptyCells($found['rows'], $levelCounts)];
        }

        $this->table(['layout', 'pairwise imbalance', 'worst pair', 'empty level-pair cells'], $rows);
        $this->newLine();
        $this->line('Lower is better on all three. "Worst pair" is the root-mean-square cell error of the least-crossed pair of');
        $this->line('attributes, divided by the count that pair should have. Empty cells are combinations no one in the cohort has,');
        $this->line('which are the cells an intersectional table cannot fill.');

        return self::SUCCESS;
    }

    private function emptyCells(array $rows, array $levelCounts): int
    {
        $factors = count($levelCounts);
        $empty = 0;

        for ($i = 0; $i < $factors; $i++) {
            for ($j = $i + 1; $j < $factors; $j++) {
                $seen = [];
                foreach ($rows as $row) {
                    $seen[$row[$i] * $levelCounts[$j] + $row[$j]] = true;
                }
                $empty += $levelCounts[$i] * $levelCounts[$j] - count($seen);
            }
        }

        return $empty;
    }
}
