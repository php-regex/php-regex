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

use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the commands that read one pattern found on the way to their
 * target, they say as lint says it: each notice of the resolution
 * ("Note: …") under the banner on the console, on stderr beside a JSON
 * document, and nothing under -q; each warning of regex.json once on
 * stderr, nothing under -q. A target error is spelled as lint spells it,
 * its control characters escaped.
 */
final class TargetNoticesTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    /**
     * @param array<string, string> $files
     * @param list<string>          $flags
     */
    #[Test]
    #[DataProvider('provideNotices')]
    public function test_the_console_prints_each_notice_under_the_banner(array $files, array $flags, string $notice): void
    {
        $directory = $this->enterProject($files);
        $this->assertContains($notice, self::notices($directory, $flags), 'The resolver gives the notice lint prints.');

        [$exitCode, $stdout, $stderr] = $this->runWithBanner([...$flags, 'validate', '/a/']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        foreach (self::notices($directory, $flags) as $each) {
            $this->assertStringContainsString('Note: '.$each."\n", $stdout);
        }
        $this->assertLessThan(strpos($stdout, 'Note: '.$notice), (int) strpos($stdout, 'Target PHP'), 'The notes follow the banner\'s target.');
    }

    /**
     * @param array<string, string> $files
     * @param list<string>          $flags
     */
    #[Test]
    #[DataProvider('provideNotices')]
    public function test_json_prints_each_notice_on_stderr(array $files, array $flags, string $notice): void
    {
        $directory = $this->enterProject($files);

        [$exitCode, $stdout, $stderr] = $this->runRegex([...$flags, 'validate', '/a/', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        JsonContract::decodeDocument($stdout);
        $this->assertStringContainsString('Note: '.$notice."\n", $stderr);
        foreach (self::notices($directory, $flags) as $each) {
            $this->assertStringContainsString('Note: '.$each."\n", $stderr);
        }
    }

    /**
     * @param array<string, string> $files
     * @param list<string>          $flags
     */
    #[Test]
    #[DataProvider('provideNotices')]
    public function test_quiet_silences_the_notices(array $files, array $flags, string $notice): void
    {
        $this->enterProject($files);

        [, $console, $consoleErrors] = $this->runWithBanner(['--quiet', ...$flags, 'validate', '/a/']);
        [, $json, $jsonErrors] = $this->runRegex(['--quiet', ...$flags, 'validate', '/a/', '--format=json']);

        // The notice is the one the other two tests see.
        $this->assertNotSame('', $notice);
        $this->assertStringNotContainsString('Note: ', $console.$consoleErrors);
        $this->assertStringNotContainsString('Note: ', $json.$jsonErrors);
    }

    /**
     * @return iterable<string, array{files: array<string, string>, flags: list<string>, notice: string}>
     */
    public static function provideNotices(): iterable
    {
        yield 'a composer.json cut short' => [
            'files' => ['composer.json' => '{"require": {"php": ">=8.1"'],
            'flags' => [],
            'notice' => 'composer.json is not a JSON object: judging for the running PHP.',
        ];
        yield 'a range that starts on PCRE1' => [
            'files' => [],
            'flags' => ['--php-version=>=7.0'],
            'notice' => '--php-version allows PHP 7.0, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP 7.3, the lowest version it allows on PCRE2.',
        ];
        yield 'a PCRE2 older than the library knows in full' => [
            'files' => [],
            'flags' => ['--php-version=8.4', '--pcre-version=10.30'],
            'notice' => 'PCRE2 10.30 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.',
        ];
    }

    /**
     * What regex.json holds that is ignored is a warning on stderr, once,
     * as lint and debug say it; stdout keeps the command's output.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideCommandsReadingRegexJson')]
    public function test_a_regex_json_warning_is_said_once_on_stderr(array $command): void
    {
        $this->enterProject(['regex.json' => '{"extraction": {"functions": ["x#a"]}}']);

        [$exitCode, $stdout, $stderr] = $this->runRegex($command);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame("Warning: Malformed pattern function \"x#a\", ignored.\n", $stderr);
        $this->assertStringNotContainsString('Malformed pattern function', $stdout);

        [, $quietStdout, $quietStderr] = $this->runRegex(['--quiet', ...$command]);

        $this->assertStringNotContainsString('Malformed pattern function', $quietStdout.$quietStderr);
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideCommandsReadingRegexJson(): iterable
    {
        yield 'validate' => ['command' => ['validate', '/a/']];
        yield 'validate, as JSON' => ['command' => ['validate', '/a/', '--format=json']];
        yield 'explain' => ['command' => ['explain', '/a/']];
        yield 'format' => ['command' => ['format', '/a/']];
        yield 'parse' => ['command' => ['parse', '/a/']];
        yield 'compare' => ['command' => ['compare', '/a/', '/b/']];
    }

    /**
     * A target error names the file it read: a COMPOSER file name holding
     * an escape sequence is spelled with it escaped, as lint spells it,
     * never written raw to the terminal.
     */
    #[Test]
    public function test_a_target_error_is_spelled_as_lint_spells_it(): void
    {
        $name = "we\e[2Jird.json";
        $this->enterProject([$name => '{"require": {"php": "5.6.*"}}', 'src/a.php' => "<?php\n"]);
        $previous = getenv('COMPOSER');
        putenv('COMPOSER='.$name);

        try {
            [$exitCode, $stdout, $stderr] = $this->runRegex(['validate', '/a/']);
            [, , $lint] = $this->runRegex(['lint', 'src', '--jobs=1']);
        } finally {
            putenv(false === $previous ? 'COMPOSER' : 'COMPOSER='.$previous);
        }

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('Error: we\x1B[2Jird.json require.php allows PHP 5.6', $lint, 'lint escapes it.');
        $this->assertSame($lint, $stderr);
        $this->assertStringNotContainsString("\e", $stderr);
    }

    /**
     * The notices of the target the flags and the files of a directory
     * resolve to, as lint reads them.
     *
     * @param list<string> $flags
     *
     * @return list<string>
     */
    private static function notices(string $directory, array $flags): array
    {
        $values = ['--php-version' => null, '--pcre-version' => null];
        foreach ($flags as $flag) {
            [$name, $value] = explode('=', $flag, 2);
            $values[$name] = $value;
        }

        return ProjectTarget::resolve($values['--php-version'], $values['--pcre-version'], [], $directory, [])->notices();
    }

    /**
     * runRegex() with the banner on, as a user runs it on a terminal.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function runWithBanner(array $arguments): array
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $application = ApplicationFactory::create(new Output(false, false, '#', '-', $errors));

        $level = ob_get_level();
        ob_start();

        try {
            $exitCode = $application->run(['regex', '--no-ansi', ...$arguments]);
            $stdout = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        rewind($errors);
        $stderr = (string) stream_get_contents($errors);
        fclose($errors);

        return [$exitCode, $stdout, $stderr];
    }
}
