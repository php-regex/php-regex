<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Integration\Bridge\Symfony;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Bridge\Symfony\Command\RegexLintCommand;
use RegexParser\Lint\Extraction\TokenBasedExtractionStrategy;
use RegexParser\Lint\PhpRegexPatternSource;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexLintService;
use RegexParser\Lint\RegexPatternExtractor;
use RegexParser\Lint\RegexPatternSourceCollection;
use RegexParser\RegexParser;
use RegexParser\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Where regex:lint says what it judges for: the console header names the
 * target and what resolving it noticed; other formats keep stdout for the
 * report and say it on stderr. A target the command cannot read stops it.
 */
final class LintCommandTargetOutputTest extends TestCase
{
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
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::PATTERN_FILE]);
        $tester = new CommandTester($this->command(projectDir: $project, phpVersion: '8.2'));

        $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        $this->assertIsArray(json_decode($tester->getDisplay(), true), 'stdout is not JSON: '.$tester->getDisplay());
        $this->assertStringContainsString('Target: PHP 8.2, PCRE2 10.40 (regex_parser.php_version)', $tester->getErrorOutput());
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

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('php_version', $tester->getDisplay());
    }

    private function command(?string $projectDir, ?string $phpVersion = null): RegexLintCommand
    {
        $analysis = new RegexAnalysisService(RegexParser::create());
        $sources = new RegexPatternSourceCollection([
            new PhpRegexPatternSource(new RegexPatternExtractor(new TokenBasedExtractionStrategy())),
        ]);

        return new RegexLintCommand(
            lint: new RegexLintService($analysis, $sources),
            analysis: $analysis,
            phpVersion: $phpVersion,
            projectDir: $projectDir,
        );
    }
}
