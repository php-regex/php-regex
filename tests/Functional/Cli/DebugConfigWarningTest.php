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

use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * regex debug reads regex.json as regex lint does: what the file holds
 * that is ignored, a pattern function it cannot read, is a warning on
 * stderr, once per spec, silenced by -q, and the command goes on; an
 * unknown extraction preset is an error.
 */
final class DebugConfigWarningTest extends TestCase
{
    use TemporaryProject;

    /**
     * @return iterable<string, array{format: list<string>}>
     */
    public static function provideFormats(): iterable
    {
        yield 'console' => ['format' => []];
        yield 'json' => ['format' => ['--format=json']];
    }

    /**
     * @param list<string> $format
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_a_malformed_pattern_function_in_regex_json_warns_once_on_stderr(array $format): void
    {
        $this->enterProject(['regex.json' => '{"extraction": {"functions": ["x#a", "App\\\\grep"]}}']);

        [$exitCode, $stdout, $stderr] = $this->runDebug(['/abc/', ...$format]);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame("Warning: Malformed pattern function \"x#a\", ignored.\n", $stderr);
        $this->assertStringNotContainsString('Malformed pattern function', $stdout);
    }

    /**
     * @param list<string> $format
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_an_unknown_preset_in_regex_json_is_an_error(array $format): void
    {
        $this->enterProject(['regex.json' => '{"extraction": {"interop": ["laravel-str", "nette-utils"]}}']);

        [$exitCode, $stdout, $stderr] = $this->runDebug(['/abc/', ...$format]);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('unknown preset "laravel-str"; did you mean "illuminate-support"?', [] === $format ? $stderr : stripslashes($stdout));
    }

    #[Test]
    public function test_quiet_hides_the_warning(): void
    {
        $this->enterProject(['regex.json' => '{"extraction": {"functions": ["x#a"]}}']);

        [$exitCode, $stdout, $stderr] = $this->runDebug(['/abc/'], quiet: true);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_a_known_preset_list_warns_nothing(): void
    {
        $this->enterProject(['regex.json' => '{"extraction": {"interop": ["nette-utils"]}}']);

        [$exitCode, $stdout, $stderr] = $this->runDebug(['/abc/']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stderr);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runDebug(array $args, bool $quiet = false): array
    {
        $command = new DebugCommand(new LintConfigLoader(), new LintDefaultsBuilder());
        $output = OutputFactory::create(quiet: $quiet);

        ob_start();

        try {
            $exitCode = $command->run(new Input('debug', $args, new GlobalOptions($quiet, false, false, true, null, null), []), $output);
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$exitCode, $stdout, OutputFactory::errors($output)];
    }
}
