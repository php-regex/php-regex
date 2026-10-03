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

use PHPRegex\Cli\Command\RedosCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The benchmark runs under the JIT setting and the limits it was asked
 * for, and gives the process back as it found it, on every way out.
 */
final class RedosCommandIniTest extends TestCase
{
    private const KEYS = ['pcre.jit', 'pcre.backtrack_limit', 'pcre.recursion_limit'];

    /**
     * @var array<string, string>
     */
    private array $before = [];

    protected function setUp(): void
    {
        $this->before = [];
        foreach (self::KEYS as $key) {
            $this->before[$key] = (string) \ini_get($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $key => $value) {
            \ini_set($key, $value);
        }
    }

    /**
     * Green before the change too: it shows the settings do apply during the
     * run, so the restore below is not restoring what was never changed.
     */
    #[Test]
    public function test_the_benchmark_runs_under_the_settings_asked_for(): void
    {
        [$exitCode, $buffer] = $this->runCommand(['/a+$/', '--input', 'aaa', '--format=json']);

        $this->assertSame(0, $exitCode);
        $payload = json_decode($buffer, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $runtime = $payload['runtime'] ?? null;
        $this->assertIsArray($runtime);
        $this->assertSame($this->otherJit(), $runtime['jit'] ?? null);
        $this->assertSame(12345, $runtime['backtrack_limit'] ?? null);
        $this->assertSame(6789, $runtime['recursion_limit'] ?? null);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_the_benchmark_restores_the_ini_it_changed(array $arguments, int $expectedExit): void
    {
        [$exitCode] = $this->runCommand($arguments);

        $this->assertSame($expectedExit, $exitCode);
        foreach ($this->before as $key => $value) {
            $this->assertSame($value, \ini_get($key), $key);
        }
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function provideRuns(): iterable
    {
        yield 'a run that completes' => [['/a+$/', '--input', 'aaa'], 0];
        yield 'a run that completes, as JSON' => [['/a+$/', '--input', 'aaa', '--format=json'], 0];
        yield 'a run that hits its own backtrack limit' => [['/(a+)+$/', '--input', 'aaaaaaaaaaaaaaaaaaaa!', '--backtrack-limit', '10'], 0];
        yield 'a run that stops on an unreadable input file' => [['/a+$/', '--input-file', '/nonexistent/regex-parser/input.txt'], 2];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runCommand(array $arguments): array
    {
        $defaults = [
            '--iterations', '1',
            '--warmup', '0',
            '--jit', $this->otherJit(),
            '--backtrack-limit', '12345',
            '--recursion-limit', '6789',
        ];
        // An argument given by the case wins over the default one.
        if (\in_array('--backtrack-limit', $arguments, true)) {
            array_splice($defaults, 6, 2);
        }

        $input = new Input(
            'redos',
            [...$arguments, ...$defaults],
            new GlobalOptions(false, false, false, true, null, null),
            [],
        );

        $level = ob_get_level();
        ob_start();

        try {
            $exitCode = (new RedosCommand())->run($input, OutputFactory::create());
            $buffer = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exitCode, $buffer];
    }

    /**
     * The JIT setting the process does not have, so running under it shows.
     */
    private function otherJit(): string
    {
        return '1' === $this->before['pcre.jit'] ? '0' : '1';
    }
}
