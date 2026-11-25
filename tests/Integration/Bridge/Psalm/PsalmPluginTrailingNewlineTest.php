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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * A subject narrowed to the strings its pattern matches holds the "\n" that
 * $ and \Z let through before the end. A docblock type cannot spell that
 * newline, so the traces Psalm prints are read instead, each union in the
 * one order every Psalm the plugin supports agrees on.
 */
final class PsalmPluginTrailingNewlineTest extends TestCase
{
    private const FILE = 'Newline/trailing_newline.php';

    #[Test]
    public function test_plugin_narrows_the_subject_with_the_final_newline(): void
    {
        $issues = PsalmRun::inFile(PsalmRun::issues([self::FILE]), self::FILE);

        $this->assertSame([
            "Trace 23:0 \$s: 'no'|'no\\n'|'yes'|'yes\\n'",
            "Trace 30:0 \$s: 'no'|'no\\n'|'yes'|'yes\\n'",
            'Trace 37:0 $s: non-falsy-string',
        ], array_map(static fn (IssueData $issue): string => \sprintf('%s %d:%d %s', $issue->type, $issue->line_from, $issue->column_from, PsalmRun::canonicalTrace($issue->message)), $issues));
    }

    /**
     * Psalm 7.19 and 7.20 print the same type in two ways; read through
     * PsalmRun::canonicalTrace(), each reads as the first.
     *
     * @return iterable<string, array{message: string, canonical: string}>
     */
    public static function provideTraces(): iterable
    {
        yield 'Psalm 7.19' => ['message' => "\$s: 'no'|'no\\n'|'yes'|'yes\\n'", 'canonical' => "\$s: 'no'|'no\\n'|'yes'|'yes\\n'"];
        yield 'Psalm 7.20' => ['message' => "\$s: \"no\\n\"|\"yes\\n\"|'no'|'yes'", 'canonical' => "\$s: 'no'|'no\\n'|'yes'|'yes\\n'"];
        yield 'a quote and a bar in a literal' => ['message' => "\$s: \"it's|\\\"\"|'a'", 'canonical' => "\$s: 'a'|'it\\'s|\"'"];
        yield 'a union inside a shape' => ['message' => "\$m: array{0: 'b'|'a', 1?: string}|null", 'canonical' => "\$m: array{0: 'b'|'a', 1?: string}|null"];
        yield 'no type' => ['message' => 'nothing to read', 'canonical' => 'nothing to read'];
    }

    #[Test]
    #[DataProvider('provideTraces')]
    public function test_canonical_trace_reads_each_printing_of_a_union_alike(string $message, string $canonical): void
    {
        $this->assertSame($canonical, PsalmRun::canonicalTrace($message));
    }
}
