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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The official images tag a PHP release by its version, "8.6", and its
 * release candidates "8.6-rc"; the "-rc" tag lives on after a GA, on the
 * candidates of the next patch release ("8.5-rc" runs 8.5.13RC1 a year after
 * 8.5.0). Every PHP the workflows run on an official image is released, so
 * a cell names its image by its PHP alone and none runs a candidate.
 */
final class PhpImageTagsTest extends TestCase
{
    private const IMAGE = 'php:${{ matrix.php }}-cli';

    /**
     * @return iterable<string, array{workflow: string, job: string}>
     */
    public static function provideContainerJobs(): iterable
    {
        yield 'the suite on the bundled PCRE2' => ['workflow' => 'engines.yml', 'job' => 'engines-bundled'];
        yield 'the engine claims' => ['workflow' => 'engines.yml', 'job' => 'engine-claims'];
        yield 'the twin' => ['workflow' => 'php74-twin.yml', 'job' => 'twin'];
    }

    #[Test]
    #[DataProvider('provideContainerJobs')]
    public function test_a_job_on_the_official_images_runs_the_release_of_its_php(string $workflow, string $job): void
    {
        $source = self::job($workflow, $job);

        $this->assertStringContainsString(self::IMAGE, $source);
        $this->assertMatchesRegularExpression("~'8\\.6'~", $source, $job.' has a PHP 8.6 row.');
        $this->assertStringNotContainsString('-rc', $source);
        $this->assertStringNotContainsString('needs.images', $source, $job.' asks no other job for its tag.');
    }

    /**
     * The official images of 8.6 ship without mbstring, which the step
     * compiles: a step that asks the image, not its name, has nothing to
     * undo when a later image brings the extension back.
     */
    #[Test]
    #[DataProvider('provideContainerJobs')]
    public function test_a_job_compiles_mbstring_only_where_the_image_lacks_it(string $workflow, string $job): void
    {
        $source = self::job($workflow, $job);
        if (!str_contains($source, 'docker-php-ext-install mbstring')) {
            $this->assertSame('engine-claims', $job, 'Only the claims, which run PHP 7.3 without dependencies, read no multibyte function.');

            return;
        }

        $this->assertSame(1, preg_match('~^      - name: Install multibyte support\n((?:        .*\n)+)~m', $source, $step));
        $this->assertStringNotContainsString('if:', $step[1]);
        $this->assertStringContainsString('extension_loaded("mbstring")', $step[1]);
    }

    /**
     * No workflow names a candidate's tag, nor keeps a job that looked one
     * up.
     */
    #[Test]
    public function test_no_workflow_runs_a_release_candidate(): void
    {
        foreach (glob(self::root().'/.github/workflows/*.yml') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression('~\d+\.\d+-rc\b~', $source, basename($path).' names a candidate.');
            $this->assertDoesNotMatchRegularExpression('~^  images:$~m', $source, basename($path).' looks up an image tag.');
        }
    }

    /**
     * The tools' locks, and the lowest Symfony the tests resolve, admit PHP
     * 8.6: no install ignores its version, as one still may on a PHP not
     * released yet.
     */
    #[Test]
    public function test_the_tools_install_on_php_8_6_without_ignoring_its_version(): void
    {
        $this->assertStringNotContainsString('--ignore-platform-req', self::job('engines.yml', 'engines-bundled'));
        $this->assertStringNotContainsString('--ignore-platform-req', self::job('tests.yml', 'tests'));

        $action = (string) file_get_contents(self::root().'/.github/actions/setup-php-project/action.yml');
        $this->assertStringNotContainsString('8.6*', $action);
        $this->assertStringContainsString('8.7*) flags+=(--ignore-platform-req=php+) ;;', $action);
    }

    private static function job(string $workflow, string $job): string
    {
        $source = (string) file_get_contents(self::root().'/.github/workflows/'.$workflow);
        self::assertSame(1, preg_match('~\n  '.preg_quote($job, '~').':\n(.*?)(?=\n  [\w-]+:\n|\z)~s', $source, $match), $workflow.' has a '.$job.' job.');

        return $match[1];
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
