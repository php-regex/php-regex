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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\GraphCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The graph command reads --format in any case, as the other commands
 * read theirs, and names every format it takes, graphviz included.
 */
final class GraphCommandFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{format: string, expected: non-empty-string}>
     */
    public static function provideFormats(): iterable
    {
        yield 'dot' => ['format' => 'dot', 'expected' => 'digraph NFA {'];
        yield 'graphviz' => ['format' => 'graphviz', 'expected' => 'digraph NFA {'];
        yield 'mermaid' => ['format' => 'mermaid', 'expected' => 'stateDiagram-v2'];
        yield 'dot in capitals' => ['format' => 'DOT', 'expected' => 'digraph NFA {'];
        yield 'graphviz capitalised' => ['format' => 'Graphviz', 'expected' => 'digraph NFA {'];
        yield 'mermaid in capitals' => ['format' => 'MERMAID', 'expected' => 'stateDiagram-v2'];
    }

    /**
     * @param non-empty-string $expected
     */
    #[DataProvider('provideFormats')]
    public function test_the_format_is_read_in_any_case(string $format, string $expected): void
    {
        [$exitCode, $written] = $this->graph(['/ab/', '--format='.$format]);

        $this->assertSame(0, $exitCode);
        $this->assertStringStartsWith($expected, $written);
    }

    public function test_an_unknown_format_is_refused_with_every_format_named(): void
    {
        [$exitCode, $written] = $this->graph(['/ab/', '--format=svg']);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString("Error: Unsupported format 'svg'. Use --format=dot, --format=graphviz or --format=mermaid.", $written);
        $this->assertStringContainsString('Usage: regex graph <pattern> [--format=dot|graphviz|mermaid] [--output=<file>]', $written);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function graph(array $args): array
    {
        $output = OutputFactory::create();
        $input = new Input('graph', $args, new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();
        $exitCode = (new GraphCommand())->run($input, $output);
        $buffer = (string) ob_get_clean();

        return [$exitCode, $buffer.OutputFactory::errors($output)];
    }
}
