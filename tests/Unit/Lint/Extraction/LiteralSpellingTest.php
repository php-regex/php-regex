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

use PHPRegex\Linter\Extraction\LiteralSpelling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether the PHP literals a pattern was written with hold a character as
 * written, or only spell it with a PHP escape. The code below is built with
 * PHP's own escapes, so that this file holds no raw tab.
 */
final class LiteralSpellingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_a_character_is_raw_only_where_the_literal_holds_it_as_written(string $expression, bool $raw): void
    {
        $code = "<?php\npreg_match(".$expression.", \$subject);\n";
        $offset = (int) strpos($code, '(') + 1;

        $this->assertSame($raw, LiteralSpelling::holdsRaw($code, $offset, "\t"));
    }

    /**
     * @return iterable<string, array{expression: string, raw: bool}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'a raw tab in single quotes' => ['expression' => "'/a\tb/'", 'raw' => true];
        yield 'a raw tab in double quotes' => ['expression' => "\"/a\tb/\"", 'raw' => true];
        yield 'a tab escape in double quotes' => ['expression' => '"/a\tb/"', 'raw' => false];
        yield 'a hexadecimal escape in double quotes' => ['expression' => '"/a\x09b/"', 'raw' => false];
        yield 'a tab escape in a concatenation' => ['expression' => "'/a' . \"\\t\" . 'b/'", 'raw' => false];
        yield 'a raw tab in the second part of a concatenation' => ['expression' => "'/a' . '\tb/'", 'raw' => true];
        yield 'a raw tab between the parts only' => ['expression' => "'/a'\t. /* \t */ \"\\tb/\"", 'raw' => false];
        yield 'a tab escape in a heredoc' => ['expression' => "<<<RE\n/a\\tb/\nRE", 'raw' => false];
        yield 'a raw tab in a heredoc' => ['expression' => "<<<RE\n/a\tb/\nRE", 'raw' => true];
        yield 'a raw tab in a nowdoc' => ['expression' => "<<<'RE'\n/a\tb/\nRE", 'raw' => true];
        yield 'no literal at the offset' => ['expression' => "\$pattern . '\t'", 'raw' => false];
    }

    #[Test]
    public function test_a_character_beyond_the_expression_is_not_read(): void
    {
        $code = "<?php\npreg_match('/a/', \"\t\");\n";

        $this->assertFalse(LiteralSpelling::holdsRaw($code, (int) strpos($code, '\''), "\t"));
    }
}
