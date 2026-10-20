<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Psalm plugin reaches a few of Psalm's internal classes, which a minor
 * release may move: its tests run on the lowest Psalm the package admits
 * and on the newest release of that major, whatever the other cells
 * resolve.
 */
final class PsalmWorkflowTest extends TestCase
{
    #[Test]
    public function test_psalm_job_runs_the_plugin_tests_on_the_floor_and_the_newest_minor(): void
    {
        $manifest = json_decode((string) file_get_contents(self::root().'/src/Psalm/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $this->assertIsArray($manifest['require'] ?? null);
        $constraint = $manifest['require']['vimeo/psalm'] ?? null;
        $this->assertIsString($constraint);
        $this->assertSame(1, preg_match('~^\^(\d+)\.(\d+)$~', $constraint, $version), 'The plugin admits one Psalm major from a floor: '.$constraint);

        $job = self::job('psalm');
        $this->assertStringContainsString("- { psalm: 'lowest', constraint: '".$version[1].'.'.$version[2].".0' }", $job);
        $this->assertStringContainsString("- { psalm: 'newest', constraint: '".$constraint."' }", $job);
        $this->assertStringContainsString('composer update -d tools/phpunit vimeo/psalm --with "vimeo/psalm:$PSALM" --with-all-dependencies', $job);
        $this->assertStringContainsString('tools/phpunit/vendor/bin/phpunit tests/Integration/Bridge/Psalm', $job);
    }

    #[Test]
    public function test_psalm_job_pins_its_actions_as_the_other_jobs_do(): void
    {
        $job = self::job('psalm');

        $this->assertStringContainsString("uses: actions/checkout@v7\n        with:\n          persist-credentials: false", $job);
        $this->assertStringContainsString('uses: ./.github/actions/setup-php-project', $job);
        $this->assertSame(1, (int) preg_match_all('~^\s+uses: ~m', $job) - substr_count($job, 'uses: ./.github/actions/'));
    }

    private static function job(string $name): string
    {
        $workflow = (string) file_get_contents(self::root().'/.github/workflows/tests.yml');
        $found = preg_match('~^  '.$name.":\n(.*?)(?=^  \\w[\\w-]*:\n|\\z)~ms", $workflow, $match);
        self::assertSame(1, $found, 'tests.yml has no '.$name.' job.');

        return $match[1];
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
