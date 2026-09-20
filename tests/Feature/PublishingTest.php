<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The static page is the only artefact most people will see, and it is generated
 * rather than written, so the things that would silently break it are worth
 * pinning: the placeholders the generator substitutes, and the fact that it
 * draws the three tabs it claims to and not the one it deliberately drops.
 */
final class PublishingTest extends TestCase
{
    public function test_the_page_template_has_the_placeholders_the_generator_fills(): void
    {
        $stub = file_get_contents(resource_path('stubs/pages.html'));

        self::assertStringContainsString('__TITLE__', $stub);
        self::assertStringContainsString('__REPORT__', $stub);
        self::assertStringContainsString('type="application/json"', $stub);
    }

    public function test_the_page_draws_the_three_tabs_and_not_the_raw_calls_one(): void
    {
        $stub = file_get_contents(resource_path('stubs/pages.html'));

        foreach (['data-tab="findings"', 'data-tab="questions"', 'data-tab="method"'] as $tab) {
            self::assertStringContainsString($tab, $stub);
        }

        self::assertStringNotContainsString('data-tab="calls"', $stub);
        self::assertStringNotContainsString('/api/runs', $stub, 'A static page must not call the Laravel API');
    }

    public function test_the_canonical_dump_is_present_and_small_enough_to_live_in_git(): void
    {
        $path = base_path('database/seed/canonical-run.sql.gz');

        self::assertFileExists($path);
        self::assertLessThan(5 * 1024 * 1024, filesize($path), 'The canonical dump has grown past what belongs in a repository');

        $handle = gzopen($path, 'rb');
        $head = gzread($handle, 4096);
        gzclose($handle);

        self::assertStringContainsString('INSERT INTO public.runs', $head);
    }
}
