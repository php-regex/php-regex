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
 * The official images tag the release candidates of PHP 8.6 "8.6-rc" until
 * its GA, 2026-11-19, and the release "8.6" from then on; the "-rc" tag
 * lives on after a GA, on the candidates of the next patch release ("8.5-rc"
 * runs 8.5.13RC1 a year after 8.5.0). A job finds the tag once per run,
 * the stable one as soon as it exists, and the rows name PHP 8.6 alone: the
 * day of the GA asks nothing of the workflows, and no cell keeps running a
 * candidate after it.
 */
final class PhpImageTagsTest extends TestCase
{
    private const IMAGE = 'php:${{ matrix.php == \'8.6\' && needs.images.outputs.php86 || matrix.php }}-cli';

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
    public function test_a_job_on_the_official_images_runs_the_tag_found_for_php_8_6(string $workflow, string $job): void
    {
        $source = self::job($workflow, $job);

        $this->assertMatchesRegularExpression('~^    needs: (?:images|\[ ?[\w-]+, images ?\])$~m', $source, $job.' waits for the tag.');
        $this->assertStringContainsString(self::IMAGE, $source);
        $this->assertMatchesRegularExpression("~'8\\.6'~", $source, $job.' has a PHP 8.6 row.');
        $this->assertStringNotContainsString('-rc', $source);
    }

    /**
     * An image without mbstring, as the candidates of 8.6 ship, compiles
     * it: a step that asks the image, not its name, has nothing to undo
     * when a later image brings the extension back.
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
     * The two workflows find the tag alike: the stable image when Docker Hub
     * serves it, the candidates before.
     */
    #[Test]
    public function test_the_tag_is_found_alike_in_both_workflows(): void
    {
        $engines = self::job('engines.yml', 'images');

        $this->assertSame($engines, self::job('php74-twin.yml', 'images'));
        $this->assertStringContainsString('docker manifest inspect php:8.6-cli', $engines);
        $this->assertStringContainsString("echo 'php86=8.6' >> \"\$GITHUB_OUTPUT\"", $engines);
        $this->assertStringContainsString("echo 'php86=8.6-rc' >> \"\$GITHUB_OUTPUT\"", $engines);
        $this->assertMatchesRegularExpression('~^    timeout-minutes: \d+$~m', $engines);
    }

    /**
     * Outside the job that finds it, no workflow names a candidate's tag.
     */
    #[Test]
    public function test_no_other_job_names_a_release_candidate(): void
    {
        foreach (glob(self::root().'/.github/workflows/*.yml') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            $source = (string) preg_replace('~\n  images:\n.*?(?=\n  [\w-]+:\n|\z)~s', "\n", $source);

            $this->assertDoesNotMatchRegularExpression('~\d+\.\d+-rc\b~', $source, basename($path).' names a candidate outside the images job.');
        }
    }

    /**
     * The tools' locks admit PHP 8.6: no install of them ignores its version
     * there, as one still may on a PHP not released yet.
     */
    #[Test]
    public function test_the_tools_install_on_php_8_6_without_ignoring_its_version(): void
    {
        $this->assertStringNotContainsString('--ignore-platform-req', self::job('engines.yml', 'engines-bundled'));

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
