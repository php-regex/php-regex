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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The body of a heredoc that interpolates is read as PHP reads it: the
 * indentation of its closing label is taken off each line, spaces or
 * tabs; the line break before the label is no part of it, whichever of
 * "\n", "\r\n" and "\r" the file uses; '\"' is no escape there, and stays
 * two bytes. A nowdoc has no escape at all.
 *
 * PHP 8.4, $part = 'x': a heredoc of the one line " /^{$part}$/" closed
 * by " RE" is "/^x$/", and so is the one indented with a tab, and the
 * one whose lines end in "\r"; the body '/^\"{$part}\"$/' is '/^\"x\"$/'
 * in a heredoc and '/^"x"$/' in double quotes; the nowdoc '/a\x41\\d\$/'
 * is those twelve bytes.
 *
 * Every case runs through both strategies.
 */
final class HeredocBodyTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideHeredocsThatInterpolate(): iterable
    {
        yield 'with no indentation' => ['argument' => "<<<RE\n/^{\$part}\$/\nRE", 'pattern' => '/^x$/'];
        yield 'indented with one space' => ['argument' => "<<<RE\n /^{\$part}\$/\n RE", 'pattern' => '/^x$/'];
        yield 'indented with two spaces, on two lines' => ['argument' => "<<<RE\n  /^{\$part}\n  \$/x\n  RE", 'pattern' => "/^x\n\$/x"];
        yield 'indented with a tab' => ['argument' => "<<<RE\n\t/^{\$part}\$/\n\tRE", 'pattern' => '/^x$/'];
        yield 'with lines ending in a carriage return' => ['argument' => "<<<RE\r/^{\$part}\$/\rRE", 'pattern' => '/^x$/'];
        yield 'with lines ending in a carriage return and a line feed' => ['argument' => "<<<RE\r\n/^{\$part}\$/\r\nRE", 'pattern' => '/^x$/'];
        yield 'with a backslash before a double quote' => ['argument' => "<<<RE\n/^\\\"{\$part}\\\"\$/\nRE", 'pattern' => '/^\\"x\\"$/'];
        yield 'ending on the expression' => ['argument' => "<<<RE\n/^a\$/{\$flags}\nRE", 'pattern' => '/^a$/i'];
    }

    #[Test]
    #[DataProvider('provideHeredocsThatInterpolate')]
    public function test_a_heredoc_that_interpolates_is_read_as_php_reads_its_body(string $argument, string $pattern): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$part = 'x';\n    \$flags = 'i';\n\n    return preg_match(".$argument.", \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * "\x41", "\\" and "\$" are escapes in a heredoc and four, two and
     * two bytes in a nowdoc.
     */
    #[Test]
    public function test_a_nowdoc_keeps_what_a_heredoc_would_decode(): void
    {
        $nowdoc = "<<<'RE'\n/a\\x41\\\\d\\\$/\nRE";
        $inArgument = "<?php\n\nfunction run(string \$s): int\n{\n    return preg_match(".$nowdoc.", \$s);\n}\n";
        $assigned = "<?php\n\nfunction run(string \$s): int\n{\n    \$re = ".$nowdoc.";\n\n    return preg_match(\$re, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/a\\x41\\\\d\\$/', 'at' => $nowdoc, 'source' => 'preg_match()', 'location' => null],
        ], $inArgument);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $nowdoc, 'source' => 'preg_match()'],
        ], $inArgument);

        $this->assertExtractedByBoth([
            ['pattern' => '/a\\x41\\\\d\\$/', 'at' => $nowdoc, 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $assigned);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $assigned);
    }
}
