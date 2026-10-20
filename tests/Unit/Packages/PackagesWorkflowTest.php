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

namespace PHPRegex\Tests\Unit\Packages;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The packages workflow checks every package as Packagist will serve it:
 * its manifest, its install alone in a bare project, and the advisories
 * against what that install pulled.
 */
final class PackagesWorkflowTest extends TestCase
{
    #[Test]
    public function test_the_standalone_job_installs_every_package(): void
    {
        $this->assertSame(1, preg_match('~^\s+package: \[ ([^\]]+) \]$~m', self::job('standalone'), $matrix), 'The standalone job has a package matrix.');
        $listed = array_map(trim(...), explode(',', $matrix[1]));
        sort($listed);

        $this->assertSame(self::packages(), $listed);
    }

    /**
     * The root manifest and the seventeen package manifests are validated
     * strictly: a warning fails the job as an error does.
     */
    #[Test]
    public function test_the_manifests_job_validates_every_manifest_strictly(): void
    {
        $job = self::job('manifests');

        $this->assertStringContainsString('composer validate --strict --no-interaction', $job);
        $this->assertStringContainsString('for manifest in composer.json src/*/composer.json; do', $job);
        $this->assertStringNotContainsString('continue-on-error', $job);
        $this->assertStringNotContainsString('|| true', $job);
    }

    /**
     * The root requires no package for an audit to read: the advisories are
     * read against what each package pulls when installed alone, the
     * dependencies a consumer of that package gets.
     */
    #[Test]
    public function test_the_standalone_job_audits_what_it_installed(): void
    {
        $job = self::job('standalone');

        $this->assertStringContainsString('run: composer audit -d "$project" --no-interaction', $job);
        $this->assertLessThan(strpos($job, 'composer audit'), strpos($job, 'composer install -d "$project"'));
        $this->assertStringNotContainsString('continue-on-error', $job);
        $this->assertStringNotContainsString('|| true', $job);
    }

    private static function job(string $name): string
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/packages.yml');
        self::assertSame(1, preg_match('~\n  '.$name.':\n(.*?)(?=\n  [\w-]+:\n|\z)~s', $workflow, $job), 'packages.yml has a '.$name.' job.');

        return $job[1];
    }

    /**
     * @return list<string>
     */
    private static function packages(): array
    {
        $packages = [];
        foreach (glob(\dirname(__DIR__, 3).'/src/*/composer.json') ?: [] as $manifest) {
            $packages[] = basename(\dirname($manifest));
        }
        sort($packages);

        self::assertCount(17, $packages);

        return $packages;
    }
}
