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
 * The docs-links workflow builds the site as php-regex.com builds it, then
 * checks every external link the built pages carry.
 */
final class DocsLinksWorkflowTest extends TestCase
{
    /**
     * The Ruby comes from docs/.ruby-version, and it is one the Bundler that
     * wrote docs/Gemfile.lock runs on: Bundler 4 needs Ruby 3.2 or later.
     */
    #[Test]
    public function test_the_site_is_built_on_a_ruby_the_locked_bundler_runs_on(): void
    {
        $this->assertFileExists(self::root().'/docs/.ruby-version');
        $rubyVersion = trim((string) file_get_contents(self::root().'/docs/.ruby-version'));
        $lock = (string) file_get_contents(self::root().'/docs/Gemfile.lock');

        $this->assertMatchesRegularExpression('~^\d+\.\d+\.\d+$~', $rubyVersion);
        $this->assertSame(1, preg_match('~^BUNDLED WITH\n\s+(\d+)\.~m', $lock, $bundler));
        if ((int) $bundler[1] >= 4) {
            $this->assertTrue(version_compare($rubyVersion, '3.2.0', '>='));
        }
    }

    #[Test]
    public function test_the_gems_of_the_lock_file_are_installed_before_the_build(): void
    {
        $workflow = self::workflow();

        $this->assertMatchesRegularExpression(
            '~uses: ruby/setup-ruby@[0-9a-f]{40} # v\d.*\n(?:\s+#.*\n)*\s+with:\n(?:\s+#.*\n)*\s+working-directory: docs\n\s+bundler-cache: true\n~',
            $workflow,
        );
        $this->assertStringContainsString("working-directory: docs\n        run: bundle exec jekyll build", $workflow);
    }

    /**
     * A pull request that changes the docs or this workflow checks the links
     * before it lands; the weekly run catches the links that died since.
     */
    #[Test]
    public function test_a_pull_request_changing_the_docs_runs_the_check(): void
    {
        $workflow = self::workflow();

        $this->assertMatchesRegularExpression(
            "~^  pull_request:\n    branches: \\[ 2\\.x \\]\n    paths:\n      - 'docs/\\*\\*'\n      - '\\.github/workflows/docs-links\\.yml'\n~m",
            $workflow,
        );
        $this->assertMatchesRegularExpression("~^  schedule:\n    - cron: '~m", $workflow);
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(self::root().'/.github/workflows/docs-links.yml');
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
