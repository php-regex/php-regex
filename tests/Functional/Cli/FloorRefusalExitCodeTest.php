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
 * Any version judged that refuses the pattern makes a command that reads
 * one pattern exit 1, the floor included, a single version included: the
 * explanation, the tree, the diagram, the laid-out pattern, the colours,
 * the graph and the benchmark are still printed, and stderr names the
 * error and the PHP that refuses the pattern. validate, analyze, debug,
 * compare and transpile exited 1 there already.
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
    public function test_a_command_exits_1_when_the_floor_refuses_and_still_prints_its_output(string $phpVersion, array $command, string $printed): void
    {
        self::assertEngineRefuses(self::REFUSED_EVERYWHERE);
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--php-version='.$phpVersion, ...$command]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString($printed, $stdout, 'The output is still printed.');
        $error = self::verdictAt(80100, self::REFUSED_EVERYWHERE)['error'];
        $this->assertIsString($error);
        $this->assertStringContainsString($error, $stderr);
        $this->assertStringContainsString('PHP 8.1', $stderr, 'stderr names the PHP that refuses the pattern.');
    }

    /**
     * @return iterable<string, array{phpVersion: string, command: list<string>, printed: string}>
     */
    public static function provideCommandsOnAPatternTheFloorRefuses(): iterable
    {
        $commands = [
            'explain' => [['explain', self::REFUSED_EVERYWHERE], 'at least 2 but not more than 1 times'],
            'parse' => [['parse', self::REFUSED_EVERYWHERE], 'Recompiled: /a{2,1}/'],
            'diagram' => [['diagram', self::REFUSED_EVERYWHERE], 'Quantifier ({2,1}, greedy)'],
            'format' => [['format', self::REFUSED_EVERYWHERE], '/a{2,1}/'],
            'highlight' => [['highlight', self::REFUSED_EVERYWHERE], '/a{2,1}/'],
            'graph' => [['graph', self::REFUSED_EVERYWHERE], 'digraph NFA'],
            // redos exits 1 already, its benchmark failing to compile the
            // pattern; stderr did not say why.
            'redos' => [['redos', self::REFUSED_EVERYWHERE], 'Vulnerable: /a{2,1}/'],
        ];
        foreach (['a single version' => '8.1', 'a range' => '>=8.1'] as $label => $phpVersion) {
            foreach ($commands as $name => [$command, $printed]) {
                yield $name.', '.$label => ['phpVersion' => $phpVersion, 'command' => $command, 'printed' => $printed];
            }
        }
    }

    /**
     * format's JSON document is the answer, the pattern laid out or given
     * back: it is still printed, its "range" says the floor refuses the
     * pattern, and the run exits 1.
     */
    #[Test]
    public function test_format_as_json_exits_1_when_the_floor_refuses(): void
    {
        self::assertEngineRefuses(self::REFUSED_EVERYWHERE);
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--php-version=8.1', 'format', self::REFUSED_EVERYWHERE, '--format=json']);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('format', $document);
        $this->assertSame('/a{2,1}/', $document['formatted'] ?? null);
        $floor = JsonContract::asArray(JsonContract::asArray($document['range'] ?? null)[0] ?? null);
        $this->assertFalse($floor['is_valid'] ?? null);
        $this->assertSame('8.1', $floor['php'] ?? null);
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
