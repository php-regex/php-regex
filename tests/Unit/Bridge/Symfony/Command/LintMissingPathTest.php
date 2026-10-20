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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A path regex:lint is given that does not exist stops the run with exit
 * code 2, as `regex lint` stops: a typo in a CI job is never a green run.
 */
final class LintMissingPathTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_a_missing_path_on_the_command_line_is_a_usage_error(): void
    {
        $tester = new CommandTester($this->command(['src']));

        $status = $tester->execute(['paths' => ['.', 'does-not-exist'], '--no-routes' => true, '--no-validators' => true], ['capture_stderr_separately' => true]);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('Path not found: does-not-exist', $tester->getDisplay().$tester->getErrorOutput());
        $this->assertStringNotContainsString('PASS', $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('provideMachineFormats')]
    public function test_a_missing_path_on_the_command_line_prints_the_usage_envelope(string $format): void
    {
        $tester = new CommandTester($this->command(['src']));

        $status = $tester->execute(['paths' => ['does-not-exist'], '--format' => $format, '--no-routes' => true, '--no-validators' => true], ['capture_stderr_separately' => true]);

        $this->assertSame(2, $status);
        if ('json' === $format) {
            $this->assertSame(['error' => 'Path not found: does-not-exist', 'stage' => 'usage'], JsonContract::decodeDocument($tester->getDisplay()));
        } else {
            $this->assertStringContainsString('Path not found: does-not-exist', $tester->getDisplay());
        }
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideMachineFormats(): iterable
    {
        yield 'json' => ['format' => 'json'];
        yield 'github' => ['format' => 'github'];
        yield 'checkstyle' => ['format' => 'checkstyle'];
        yield 'junit' => ['format' => 'junit'];
    }

    #[Test]
    public function test_a_missing_configured_path_is_a_configuration_error_naming_the_setting(): void
    {
        $project = $this->makeProject(['src/.keep' => '']);
        $tester = new CommandTester($this->command(['src', 'lib'], $project));

        $status = $tester->execute(['--format' => 'json', '--no-routes' => true, '--no-validators' => true], ['capture_stderr_separately' => true]);

        $this->assertSame(2, $status);
        $this->assertSame(['error' => 'Path not found: '.$project.'/lib (from php_regex.paths)', 'stage' => 'config'], JsonContract::decodeDocument($tester->getDisplay()));
    }

    /**
     * @param list<string> $paths
     */
    private function command(array $paths, ?string $projectDir = null): LintCommand
    {
        $analysis = new AnalysisService(RegexParser::create());

        return new LintCommand(
            lint: new LintService($analysis, new PatternSourceCollection([])),
            analysis: $analysis,
            defaultPaths: $paths,
            projectDir: $projectDir,
        );
    }
}
