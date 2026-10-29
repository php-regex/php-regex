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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintArgumentParser;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * --no-visuals leaves the lint's banner out, as it does for every other
 * command, and the footer prints no figure the run did not measure.
 */
final class LintVisualsTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/php-regex-lint-visuals-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory.'/empty.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    #[Test]
    public function test_no_visuals_leaves_the_banner_out(): void
    {
        $output = $this->lint(visuals: false);

        $this->assertStringNotContainsString('Runtime', $output);
        $this->assertStringNotContainsString('Recursion', $output);
    }

    #[Test]
    public function test_the_banner_shows_with_the_visuals(): void
    {
        $this->assertStringContainsString('Runtime', $this->lint(visuals: true));
    }

    #[Test]
    public function test_the_footer_prints_no_cache_figure(): void
    {
        $output = $this->lint(visuals: true, verbose: true);

        $this->assertStringContainsString('a GitHub star is appreciated', $output);
        $this->assertStringNotContainsString('Cache: 0 hits, 0 misses', $output);
    }

    /**
     * The author and the request for a star are for -v: a plain run names
     * neither, as the bridges' regex:lint names neither.
     */
    #[Test]
    public function test_the_author_and_the_footer_show_under_verbose_only(): void
    {
        $output = $this->lint(visuals: true);
        $verbose = $this->lint(visuals: true, verbose: true);

        $this->assertMatchesRegularExpression('/^PHPRegex \S+\n/', $output);
        $this->assertStringNotContainsString('by Younes ENNAJI', $output);
        $this->assertStringNotContainsString('GitHub star', $output);
        $this->assertMatchesRegularExpression('/^PHPRegex \S+ by Younes ENNAJI\n/', $verbose);
        $this->assertStringContainsString('GitHub star', $verbose);
    }

    /**
     * Under -v a run that finds a pattern ends on the footer too.
     */
    #[Test]
    public function test_a_verbose_report_ends_on_the_footer(): void
    {
        file_put_contents($this->directory.'/pattern.php', "<?php\npreg_match('/[a-z/', \$s);\n");

        $this->assertStringEndsWith("a GitHub star is appreciated: https://github.com/php-regex/php-regex\n\n", $this->lint(visuals: true, verbose: true));
        $this->assertStringNotContainsString('GitHub star', $this->lint(visuals: true));
    }

    private function lint(bool $visuals, bool $verbose = false): string
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $input = new Input('lint', [$this->directory, '--jobs=1', ...($verbose ? ['--verbose'] : [])], new GlobalOptions(false, false, false, $visuals, null, null), []);

        $cwd = (string) getcwd();
        chdir($this->directory);
        ob_start();

        try {
            $command->run($input, OutputFactory::create());
        } finally {
            $stdout = (string) ob_get_clean();
            chdir($cwd);
        }

        return $stdout;
    }
}
