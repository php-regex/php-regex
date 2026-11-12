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

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\SinglePatternRange;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the floor refuses keeps the exit code each command that reads
 * one pattern gives it at a single version, a range included: explain,
 * parse, diagram, format, highlight and graph print what they read of it
 * and exit 0, redos exits 1, its benchmark failing to compile it. Only a
 * later version of a range that refuses a pattern the floor accepts makes
 * them exit 1 (SinglePatternCommandRangeTest): the floor's refusal is the
 * command's own verdict, and stderr adds nothing to it.
 *
 * "a{2,1}" is refused by every PCRE2 (pcre2test and preg_match():
 * "numbers out of order in {} quantifier"), read first on the running
 * engine; the library still draws, explains and lays it out.
 */
final class FloorRefusalExitCodeTest extends TestCase
{
    use RunsRegexCli;
    use SinglePatternRange;
    use TemporaryProject;

    private const REFUSED_EVERYWHERE = '/a{2,1}/';

    /**
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideCommandsOnAPatternTheFloorRefuses')]
    public function test_a_command_keeps_its_exit_code_when_the_floor_refuses(string $phpVersion, array $command, int $expected, string $printed): void
    {
        self::assertEngineRefuses(self::REFUSED_EVERYWHERE);
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--php-version='.$phpVersion, ...$command]);
        [$singleExitCode] = $this->runRegex(['--php-version=8.1', ...$command]);

        $this->assertSame($expected, $exitCode, $stdout.$stderr);
        $this->assertSame($singleExitCode, $exitCode, 'The range keeps the exit code of its floor alone.');
        $this->assertStringContainsString($printed, $stdout, 'The output is still printed.');
        $this->assertSame('', $stderr, 'The floor\'s refusal adds nothing on stderr.');
    }

    /**
     * @return iterable<string, array{phpVersion: string, command: list<string>, expected: int, printed: string}>
     */
    public static function provideCommandsOnAPatternTheFloorRefuses(): iterable
    {
        $commands = [
            'explain' => [['explain', self::REFUSED_EVERYWHERE], 0, 'at least 2 but not more than 1 times'],
            'parse' => [['parse', self::REFUSED_EVERYWHERE], 0, 'Recompiled: /a{2,1}/'],
            'diagram' => [['diagram', self::REFUSED_EVERYWHERE], 0, 'Quantifier ({2,1}, greedy)'],
            'format' => [['format', self::REFUSED_EVERYWHERE], 0, '/a{2,1}/'],
            'highlight' => [['highlight', self::REFUSED_EVERYWHERE], 0, '/a{2,1}/'],
            'graph' => [['graph', self::REFUSED_EVERYWHERE], 0, 'digraph NFA'],
            'redos' => [['redos', self::REFUSED_EVERYWHERE], 1, 'Vulnerable: /a{2,1}/'],
        ];
        foreach (['a single version' => '8.1', 'a range' => '>=8.1'] as $label => $phpVersion) {
            foreach ($commands as $name => [$command, $expected, $printed]) {
                yield $name.', '.$label => ['phpVersion' => $phpVersion, 'command' => $command, 'expected' => $expected, 'printed' => $printed];
            }
        }
    }

    /**
     * format's JSON document is the answer, the pattern laid out or given
     * back: its "range" says the floor refuses the pattern, and the run
     * exits 0, as at a single version.
     */
    #[Test]
    public function test_format_as_json_keeps_exit_code_0_when_the_floor_refuses(): void
    {
        self::assertEngineRefuses(self::REFUSED_EVERYWHERE);
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--php-version=>=8.1', 'format', self::REFUSED_EVERYWHERE, '--format=json']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('format', $document);
        $this->assertSame('/a{2,1}/', $document['formatted'] ?? null);
        $range = JsonContract::asArray($document['range'] ?? null);
        $floor = JsonContract::asArray($range[0] ?? null);
        $this->assertFalse($floor['is_valid'] ?? null);
        $this->assertSame('8.1', $floor['php'] ?? null);
        $this->assertSame(self::verdictAt(80100, self::REFUSED_EVERYWHERE)['error'], $floor['error'] ?? null);
        $this->assertGreaterThan(1, \count($range), 'The later versions are judged too, and refuse it as well.');
    }

    /**
     * explain and redos answer a pattern the floor refuses with the
     * envelope, stage "pattern", exit 1, as before.
     */
    #[Test]
    #[DataProvider('provideJsonCommandsStoppingAtTheFloor')]
    public function test_a_json_command_that_stops_at_the_floor_keeps_its_envelope(string $command): void
    {
        self::assertEngineRefuses(self::REFUSED_EVERYWHERE);
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['--php-version=>=8.1', $command, self::REFUSED_EVERYWHERE, '--format=json']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('error', $document);
        $this->assertSame('pattern', $document['stage'] ?? null);
    }

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideJsonCommandsStoppingAtTheFloor(): iterable
    {
        yield 'explain' => ['command' => 'explain'];
        yield 'redos' => ['command' => 'redos'];
    }

    /**
     * The running engine refuses the pattern too.
     */
    private static function assertEngineRefuses(string $pattern): void
    {
        self::assertFalse(@preg_match($pattern, 'aa'), 'PCRE2 '.\PCRE_VERSION);
    }
}
