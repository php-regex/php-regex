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

use PHPRegex\Cli\SelfUpdate\SelfUpdater;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The phar a release carries is built by the phar workflow, never on a
 * maintainer's machine: the workflow attests where it was built, and attaches
 * it under the names self-update downloads.
 */
final class PharWorkflowTest extends TestCase
{
    #[Test]
    public function test_a_pushed_version_tag_starts_the_workflow(): void
    {
        $workflow = self::workflow();

        $this->assertMatchesRegularExpression("~^on:\n  push:\n    tags: \\['v\\*'\\]$~m", $workflow);
        $this->assertStringContainsString('workflow_dispatch:', $workflow);
    }

    #[Test]
    public function test_the_phar_is_built_as_the_release_script_built_it_and_attested(): void
    {
        $workflow = self::workflow();

        $this->assertStringContainsString('run: phar/build', $workflow);
        $this->assertStringContainsString('uses: actions/attest-build-provenance@', $workflow);
        $this->assertStringContainsString('subject-path: bin/regex.phar', $workflow);
        $this->assertStringContainsString('id-token: write', $workflow);
        $this->assertStringContainsString('attestations: write', $workflow);
        $this->assertStringContainsString('contents: write', $workflow);
    }

    /**
     * The attachments are named after the paths self-update downloads, and
     * the release is published only once they are attached.
     */
    #[Test]
    public function test_the_assets_carry_the_names_self_update_downloads(): void
    {
        $workflow = self::workflow();
        $updater = new class extends SelfUpdater {
            public function updateUrl(): string
            {
                return $this->getUpdateUrl();
            }

            public function checksumUrl(): string
            {
                return $this->getChecksumUrl();
            }
        };

        $this->assertStringEndsWith('/regex.phar', $updater->updateUrl());
        $this->assertStringEndsWith('/regex.phar.sha256', $updater->checksumUrl());
        $this->assertStringContainsString('gh release upload "$TAG" bin/regex.phar bin/regex.phar.sha256 --clobber', $workflow);
        $this->assertLessThan(
            strpos($workflow, 'gh release edit "$TAG" --draft=false'),
            strpos($workflow, 'gh release upload "$TAG"'),
        );
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/phar.yml');
    }
}
