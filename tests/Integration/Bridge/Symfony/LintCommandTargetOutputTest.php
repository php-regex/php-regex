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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Where regex:lint says what it judges for: the console header names the
 * target and what resolving it noticed; other formats keep stdout for the
 * report and say it on stderr. A target the command cannot read stops it.
 */
final class LintCommandTargetOutputTest extends TestCase
{
    use PcreRule;
    use TemporaryProject;

    private const PATTERN_FILE = "<?php\n\npreg_match('/^[a-z]+\$/', \$subject);\n";

    #[Test]
    public function test_the_console_header_names_the_target_and_its_notices(): void
    {
        // A composer.json without require.php: the running PHP, with a note.
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::PATTERN_FILE]);
        $tester = new CommandTester($this->command(projectDir: $project));

        $status = $tester->execute(['paths' => [$project.'/src'], '--no-routes' => true, '--no-validators' => true, '--jobs' => '1']);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $status, $display);
        $this->assertStringContainsString('running PHP', $display);
        $this->assertStringContainsString('Note: ', $display);
        $this->assertStringContainsString('require.php', $display);
    }

    #[Test]
    public function test_other_formats_say_the_target_on_stderr(): void
    {
        $this->requiresBundledPcre2(80200, __FUNCTION__);
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::PATTERN_FILE]);
        $tester = new CommandTester($this->command(projectDir: $project, phpVersion: '8.2'));

        $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        $this->assertIsArray(json_decode($tester->getDisplay(), true), 'stdout is not JSON: '.$tester->getDisplay());
        $this->assertStringContainsString('Target: PHP 8.2, PCRE2 10.40 (php_regex.php_version)', $tester->getErrorOutput());
    }

    #[Test]
    public function test_stderr_carries_the_notices_too(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::PATTERN_FILE]);
        $tester = new CommandTester($this->command(projectDir: $project));

        $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'github', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        $this->assertStringContainsString('Note: ', $tester->getErrorOutput());
        $this->assertStringNotContainsString('Note: ', $tester->getDisplay());
    }

    #[Test]
    public function test_a_target_the_command_cannot_read_stops_it(): void
    {
        // The bundle refuses it at compile; a command wired by hand may not.
        $tester = new CommandTester($this->command(projectDir: null, phpVersion: 'eight'));

        $status = $tester->execute(['paths' => ['.'], '--no-routes' => true, '--no-validators' => true]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('php_version', $tester->getDisplay());
    }

    /**
     * PHP 7.2 and earlier ship PCRE1: the command refuses to judge them,
     * with the stage the CLI names the refusal with.
     */
    #[Test]
    public function test_a_target_on_pcre1_stops_it_with_the_target_stage(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::PATTERN_FILE]);
        $tester = new CommandTester($this->command(projectDir: $project, phpVersion: '7.2'));

        $status = $tester->execute(['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1']);

        $this->assertSame(Command::INVALID, $status);
        $this->assertSame(
            ['error' => 'php_regex.php_version names PHP 7.2: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.', 'stage' => 'target'],
            json_decode($tester->getDisplay(), true),
        );
    }

    /**
     * A require.php reaching from PCRE1 into PCRE2 is judged from 7.3, not
     * refused: only a version the bundle settings name is.
     */
    #[Test]
    public function test_a_range_from_below_7_3_is_judged_from_its_lowest_php_on_pcre2(): void
    {
        $project = $this->makeProject(['composer.json' => '{"require": {"php": "^7.1 || ^8.0"}}', 'src/Pattern.php' => self::PATTERN_FILE]);
        $tester = new CommandTester($this->command(projectDir: $project));

        $status = $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'github', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(Command::SUCCESS, $status, $tester->getErrorOutput());
        $this->assertStringContainsString(
            "Note: composer.json require.php allows PHP 7.1, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP 7.3, the lowest version it allows on PCRE2.\n",
            $tester->getErrorOutput(),
        );
        $this->assertStringContainsString('Target: PHP 7.3, PCRE2 10.32 (composer.json require.php)', $tester->getErrorOutput());
    }

    private function command(?string $projectDir, ?string $phpVersion = null): LintCommand
    {
        $analysis = new AnalysisService(RegexParser::create());
        $sources = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy())),
        ]);

        return new LintCommand(
            lint: new LintService($analysis, $sources),
            analysis: $analysis,
            phpVersion: $phpVersion,
            projectDir: $projectDir,
        );
    }
}
