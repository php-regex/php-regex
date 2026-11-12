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
 * What the commands that read one pattern found on the way to the target
 * the command line names, they say as lint says it: each notice of the
 * resolution ("Note: …") under the banner on the console, on stderr beside
 * a JSON document, and nothing under -q. They read no file of the working
 * directory: what regex.json or composer.json holds is never noted.
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
        $this->enterProject($files);
        $this->assertContains($notice, self::notices($flags), 'The resolver gives the notice lint prints.');

        [$exitCode, $stdout, $stderr] = $this->runWithBanner([...$flags, 'validate', '/a/']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        foreach (self::notices($flags) as $each) {
            $this->assertStringContainsString('Note: '.$each."\n", $stdout);
        }
        $this->assertLessThan(strpos($stdout, 'Note: '.$notice), (int) strpos($stdout, 'Target PHP'), 'The notes follow the banner\'s target.');
    }

    /**
     * Without the banner, --no-visuals, the console has nowhere to say what
     * resolving the target noticed but stderr: it says it there, for every
     * command that reads one pattern, and stdout keeps the command's own
     * output.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideConsoleCommands')]
    public function test_without_visuals_the_console_prints_each_notice_on_stderr(array $command): void
    {
        $this->enterProject();
        $flags = ['--php-version=>=7.0', '--pcre-version=10.30'];
        $notices = self::notices($flags);
        $this->assertCount(2, $notices, 'The clip to 7.3 and the PCRE2 judged with older rules.');

        [, $stdout, $stderr] = $this->runRegex([...$flags, ...$command]);

        foreach ($notices as $notice) {
            $this->assertStringContainsString('Note: '.$notice."\n", $stderr);
            $this->assertStringNotContainsString($notice, $stdout);
        }
        $this->assertSame(\count($notices), substr_count($stderr, 'Note: '), 'Each notice is said once.');
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideConsoleCommands(): iterable
    {
        yield 'validate' => ['command' => ['validate', '/a/']];
        yield 'analyze' => ['command' => ['analyze', '/a/']];
        yield 'explain' => ['command' => ['explain', '/a/']];
        yield 'parse' => ['command' => ['parse', '/a/']];
        yield 'diagram' => ['command' => ['diagram', '/a/']];
        yield 'highlight' => ['command' => ['highlight', '/a/']];
        yield 'debug' => ['command' => ['debug', '/a/']];
        yield 'compare' => ['command' => ['compare', '/a/', '/b/']];
        yield 'redos' => ['command' => ['redos', '/a/', '--iterations=1', '--warmup=0']];
        yield 'format' => ['command' => ['format', '/a/']];
        yield 'transpile' => ['command' => ['transpile', '/a/']];
        yield 'graph' => ['command' => ['graph', '/a/']];
    }

    /**
     * @param array<string, string> $files
     * @param list<string>          $flags
     */
    #[Test]
    #[DataProvider('provideNotices')]
    public function test_json_prints_each_notice_on_stderr(array $files, array $flags, string $notice): void
    {
        $this->enterProject($files);

        [$exitCode, $stdout, $stderr] = $this->runRegex([...$flags, 'validate', '/a/', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        JsonContract::decodeDocument($stdout);
        $this->assertStringContainsString('Note: '.$notice."\n", $stderr);
        foreach (self::notices($flags) as $each) {
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
        [, $plain, $plainErrors] = $this->runRegex(['--quiet', ...$flags, 'validate', '/a/']);

        // The notice is the one the other two tests see.
        $this->assertNotSame('', $notice);
        $this->assertStringNotContainsString('Note: ', $console.$consoleErrors);
        $this->assertStringNotContainsString('Note: ', $plain.$plainErrors, 'Nor on stderr when there is no banner.');
        $this->assertStringNotContainsString('Note: ', $json.$jsonErrors);
    }

    /**
     * @return iterable<string, array{files: array<string, string>, flags: list<string>, notice: string}>
     */
    public static function provideNotices(): iterable
    {
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
     * What regex.json holds that is ignored, or a composer.json cut short,
     * is lint's to say: these commands read neither, and say nothing of
     * them, where lint warns and notes.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_the_files_of_the_working_directory_are_never_noted(array $command): void
    {
        $this->enterProject(['regex.json' => '{"extraction": {"functions": ["x#a"]}}', 'composer.json' => '{"require": {"php": ">=8.1"', 'src/a.php' => "<?php\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex($command);
        [, , $lint] = $this->runRegex(['lint', 'src', '--jobs=1']);

        $this->assertStringContainsString('Malformed pattern function "x#a", ignored.', $lint, 'lint reads regex.json.');
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stderr);
        $this->assertStringNotContainsString('Malformed pattern function', $stdout);
        $this->assertStringNotContainsString('composer.json', $stdout);
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideCommands(): iterable
    {
        yield 'validate' => ['command' => ['validate', '/a/']];
        yield 'validate, as JSON' => ['command' => ['validate', '/a/', '--format=json']];
        yield 'explain' => ['command' => ['explain', '/a/']];
        yield 'format' => ['command' => ['format', '/a/']];
        yield 'parse' => ['command' => ['parse', '/a/']];
        yield 'compare' => ['command' => ['compare', '/a/', '/b/']];
    }

    /**
     * A COMPOSER file lint refuses to judge for is not read either: the
     * command judges the running PHP.
     */
    #[Test]
    public function test_the_composer_file_the_environment_names_is_not_read(): void
    {
        $name = 'project-composer.json';
        $this->enterProject([$name => '{"require": {"php": "5.6.*"}}', 'src/a.php' => "<?php\n"]);
        $previous = getenv('COMPOSER');
        putenv('COMPOSER='.$name);

        try {
            [$exitCode, $stdout, $stderr] = $this->runRegex(['validate', '/a/']);
            [$lintExitCode, , $lint] = $this->runRegex(['lint', 'src', '--jobs=1']);
        } finally {
            putenv(false === $previous ? 'COMPOSER' : 'COMPOSER='.$previous);
        }

        $this->assertSame(2, $lintExitCode, $lint);
        $this->assertStringContainsString('require.php allows PHP 5.6', $lint, 'lint reads it.');
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stderr);
    }

    /**
     * The notices of the target the flags resolve to, as lint reads them
     * from the same flags: no file is read.
     *
     * @param list<string> $flags
     *
     * @return list<string>
     */
    private static function notices(array $flags): array
    {
        $values = ['--php-version' => null, '--pcre-version' => null];
        foreach ($flags as $flag) {
            [$name, $value] = explode('=', $flag, 2);
            $values[$name] = $value;
        }

        return ProjectTarget::fromSources(['--php-version' => $values['--php-version']], ['--pcre-version' => $values['--pcre-version']], null, [])->notices();
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
