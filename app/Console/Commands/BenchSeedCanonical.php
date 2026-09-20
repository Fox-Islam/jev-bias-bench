<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bench\Analysis\ReportStore;
use App\Models\Outcome;
use App\Models\Person;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the run this repository ships with.
 *
 * A benchmark whose findings cannot be opened is a claim rather than a result,
 * so the run behind FINDINGS.md travels with the code: every call, every answer
 * and every payload, compressed to under half a megabyte. A fresh checkout gets
 * a populated dashboard without an API key and without spending anything.
 */
class BenchSeedCanonical extends Command
{
    protected $signature = 'bench:seed-canonical
        {--file=database/seed/canonical-run.sql.gz : The dump to load}
        {--name=deep : The run the dump contains}
        {--force : Replace that run if it is already loaded}';

    protected $description = 'Load the canonical benchmark run that ships with this repository';

    public function handle(ReportStore $store): int
    {
        $path = base_path((string) $this->option('file'));

        if (! is_readable($path)) {
            $this->error("No dump at {$path}.");

            return self::FAILURE;
        }

        // Loading restores the dump's own primary keys and rewinds the
        // sequences to match, so anything mid-flight would start colliding with
        // ids it had already been given.
        if (Run::where('status', 'running')->exists()) {
            $this->error('A run is in progress. Wait for it to finish, or stop it, before loading a dump.');

            return self::FAILURE;
        }

        $name = (string) $this->option('name');
        $existing = Run::where('name', $name)->first();

        if ($existing !== null) {
            if (! $this->option('force')) {
                $this->warn("A run named [{$name}] is already loaded. Pass --force to replace it.");

                return self::FAILURE;
            }

            $this->line("Replacing the existing [{$name}] run...");
            $existing->delete();
        }

        $this->line('Loading '.number_format(filesize($path) / 1024).'KB of compressed run data...');

        $statements = 0;
        try {
            DB::transaction(function () use ($path, &$statements) {
                foreach ($this->statements($path) as $statement) {
                    DB::unprepared($statement);
                    $statements++;
                }
            });
        } finally {
            // The dump blanks the search path so its own qualified names resolve
            // predictably; anything else on this connection needs it back.
            DB::unprepared("SELECT pg_catalog.set_config('search_path', 'public', false);");
        }

        $this->advanceSequences();

        $this->info(sprintf(
            'Loaded %d statements: %d run, %d people, %d calls, %d answers.',
            $statements,
            Run::count(),
            Person::count(),
            Probe::count(),
            Outcome::count(),
        ));

        // The report is derived, so it is not in the dump. Computing it here
        // rather than on the first page load keeps the dashboard from hanging
        // for a minute the first time someone opens it.
        $run = Run::latest('id')->first();
        if ($run !== null) {
            $this->line('Computing the report...');
            $store->put($run);
            $this->line('Report at <info>storage/app/private/'.$store->path($run).'</info>');
        }

        $this->newLine();
        $this->line('Now: <info>php artisan bench:analyse</info>, or open the dashboard.');

        return self::SUCCESS;
    }

    /**
     * Puts every sequence ahead of the rows now in its table.
     *
     * The dump ends by setting each sequence to the last id it contains, which
     * is correct for an empty database and wrong for one that already holds a
     * later run: the next insert would be handed an id that is already taken.
     */
    private function advanceSequences(): void
    {
        foreach (['runs', 'personas', 'probes', 'outcomes'] as $table) {
            DB::unprepared(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), "
                ."GREATEST(COALESCE((SELECT MAX(id) FROM {$table}), 0), 1), true);"
            );
        }
    }

    /**
     * Splits the dump into statements without loading 13MB into memory.
     *
     * A statement ends at a line finishing with a semicolon, but only when every
     * quote opened so far has been closed — JSON payloads are full of semicolons
     * and a naive split would cut one in half. pg_dump writes a literal quote as
     * two quotes, which leaves the running parity unchanged, so counting is
     * enough and no escape handling is needed.
     *
     * @return \Generator<string>
     */
    private function statements(string $path): \Generator
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path}.");
        }

        $buffer = '';
        $open = false;

        try {
            while (($line = gzgets($handle)) !== false) {
                $trimmed = rtrim($line, "\r\n");

                // Blank lines, comments, and the \restrict / \unrestrict
                // meta-commands pg_dump 18 writes for psql, which are not SQL.
                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '\\'))) {
                    continue;
                }

                $buffer .= ($buffer === '' ? '' : "\n").$trimmed;

                if (substr_count($trimmed, "'") % 2 === 1) {
                    $open = ! $open;
                }

                if (! $open && str_ends_with($trimmed, ';')) {
                    yield $buffer;
                    $buffer = '';
                }
            }
        } finally {
            gzclose($handle);
        }

        if (trim($buffer) !== '') {
            yield $buffer;
        }
    }
}
