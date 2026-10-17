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

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\GuardRegions;
use PHPRegex\Linter\Extraction\PhpParserGuards;
use PHPRegex\Linter\Extraction\TokenGuards;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The versions the guards around a place of a source keep, as each reader
 * finds them: the PHP parser's and the tokenizer's, the same for every form
 * both read. Each case names the literal it asks about, "'@'", and what the
 * guards keep there, null for the whole range.
 */
final class GuardRegionsTest extends TestCase
{
    /**
     * @return iterable<string, array{code: string, expected: string|null}>
     */
    public static function provideBothReaders(): iterable
    {
        yield 'else if' => ['code' => "if (PHP_VERSION_ID < 80300) {\n} else if (PHP_VERSION_ID < 80400) {\n    f('@');\n}", 'expected' => '>=8.3 <8.4'];
        yield 'in parentheses' => ['code' => "if ((PHP_VERSION_ID >= 80400)) {\n    f('@');\n}", 'expected' => '>=8.4'];
        yield '&& in parentheses' => ['code' => "if ((PHP_VERSION_ID >= 80300) && (PHP_VERSION_ID >= 80400)) {\n    f('@');\n}", 'expected' => '>=8.4'];
        yield 'the else of two guards that overlap' => ['code' => "if (PHP_VERSION_ID >= 80300 && PHP_VERSION_ID >= 80400) {\n} else {\n    f('@');\n}", 'expected' => '<8.4'];
        yield 'an equality the other way round' => ['code' => "if (80400 === PHP_VERSION_ID) {\n    f('@');\n}", 'expected' => '>=8.4 <8.4.1'];
        yield 'an empty body leaves the rest' => ['code' => "if (PHP_VERSION_ID >= 80400) {}\nf('@');", 'expected' => null];
        yield 'a float' => ['code' => "if (PHP_VERSION_ID >= 8.4) {\n    f('@');\n}", 'expected' => null];
        yield 'an arithmetic operator' => ['code' => "if (PHP_VERSION_ID + 80400) {\n    f('@');\n}", 'expected' => null];
        yield 'a version in a variable' => ['code' => "if (version_compare(PHP_VERSION, \$v, '>=')) {\n    f('@');\n}", 'expected' => null];
        yield 'version_compare the other way round' => ['code' => "if (version_compare('8.4', PHP_VERSION, '<=')) {\n    f('@');\n}", 'expected' => null];
        yield 'a version with a variable in double quotes' => ['code' => "if (version_compare(PHP_VERSION, \"8.\$minor\", '>=')) {\n    f('@');\n}", 'expected' => null];
        yield 'a guard written with a backslash' => ['code' => "if (\\version_compare(\\PHP_VERSION, '8.4', 'ge')) {\n    f('@');\n}", 'expected' => '>=8.4'];
        yield 'an exit with a value' => ['code' => "if (PHP_VERSION_ID < 80400) {\n    exit(1);\n}\nf('@');", 'expected' => '>=8.4'];
        yield 'a break' => ['code' => "foreach (\$a as \$b) {\n    if (PHP_VERSION_ID < 80400) {\n        break;\n    }\n    f('@');\n}\nf(1);", 'expected' => '>=8.4'];
        yield 'an exit and more' => ['code' => "if (PHP_VERSION_ID < 80400) {\n    exit(1); f(2);\n}\nf('@');", 'expected' => null];
        yield 'a body that is no exit' => ['code' => "if (PHP_VERSION_ID < 80400) {\n    f(2);\n}\nf('@');", 'expected' => null];
        yield 'an early exit with an else' => ['code' => "if (PHP_VERSION_ID < 80400) {\n    return;\n} else {\n}\nf('@');", 'expected' => null];
        yield 'past the block of an early exit' => ['code' => "function g() {\n    if (PHP_VERSION_ID < 80400) {\n        return;\n    }\n}\nf('@');", 'expected' => null];
    }

    #[Test]
    #[DataProvider('provideBothReaders')]
    public function test_both_readers_keep_the_same_versions(string $code, ?string $expected): void
    {
        $content = "<?php\n".$code."\n";
        $offset = (int) strpos($content, "'@'");

        $this->assertSame($expected, self::tokens($content)->at($offset)?->phpConstraint(), 'tokenizer');
        $this->assertSame($expected, self::parser($content)->at($offset)?->phpConstraint(), 'php-parser');
    }

    /**
     * The PHP parser reads the alternative syntax as any "if"; the
     * tokenizer does not, and keeps the whole range.
     */
    #[Test]
    public function test_only_the_php_parser_reads_the_alternative_syntax(): void
    {
        $content = "<?php\nif (PHP_VERSION_ID >= 80400):\n    f('@');\nendif;\n";
        $offset = (int) strpos($content, "'@'");

        $this->assertSame('>=8.4', self::parser($content)->at($offset)?->phpConstraint());
        $this->assertNull(self::tokens($content)->at($offset));
    }

    /**
     * The tokenizer reads files the PHP parser refuses: an "if" it cannot
     * read to its end is no guard.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideBrokenSources(): iterable
    {
        yield 'no condition' => ['code' => "if PHP_VERSION_ID >= 80400 {\n    f('@');\n}"];
        yield 'a statement cut by a brace' => ['code' => "{\n    if (PHP_VERSION_ID >= 80400) f('@')\n}"];
        yield 'a statement cut by the end' => ['code' => "if (PHP_VERSION_ID >= 80400) f('@')"];
    }

    #[Test]
    #[DataProvider('provideBrokenSources')]
    public function test_a_broken_if_is_no_guard(string $code): void
    {
        $content = "<?php\n".$code."\n";

        $this->assertNull(self::tokens($content)->at((int) strpos($content, "'@'")));
    }

    /**
     * A "match (true)" arm holds where any of its conditions holds, all of
     * them guards; one that is not makes the arm no guard. The tokenizer
     * reads no arm.
     */
    #[Test]
    public function test_a_match_arm_is_a_guard_when_all_its_conditions_are(): void
    {
        $content = "<?php\n\$r = match (true) {\n    PHP_VERSION_ID < 80300, PHP_VERSION_ID >= 80500 => f('@'),\n    PHP_VERSION_ID >= 80400, \$x => f('#'),\n    default => f('%'),\n};\n";

        $parser = self::parser($content);
        $this->assertSame('<8.3 || >=8.5', $parser->at((int) strpos($content, "'@'"))?->phpConstraint());
        $this->assertNull($parser->at((int) strpos($content, "'#'")));
        $this->assertNull($parser->at((int) strpos($content, "'%'")));
        $this->assertNull(self::tokens($content)->at((int) strpos($content, "'@'")));
    }

    /**
     * A source that never names PHP_VERSION is not read, and an offset not
     * known keeps the whole range.
     */
    #[Test]
    public function test_a_source_without_a_guard_and_an_unknown_offset_keep_the_whole_range(): void
    {
        $content = "<?php\nif (\$x) {\n    f('@');\n}\n";

        $this->assertNull(self::tokens($content)->at((int) strpos($content, "'@'")));
        $this->assertNull(self::tokens("<?php\nif (PHP_VERSION_ID >= 80400) {\n    f('@');\n}\n")->at(null));
    }

    private static function tokens(string $content): GuardRegions
    {
        $tokens = token_get_all($content);
        $offsets = [];
        $offset = 0;
        foreach ($tokens as $index => $token) {
            $offsets[$index] = $offset;
            $offset += \strlen(\is_array($token) ? $token[1] : $token);
        }

        $closers = [];
        $open = [];
        foreach ($tokens as $index => $token) {
            if ('(' === $token || '[' === $token || '{' === $token || (\is_array($token) && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES, \T_ATTRIBUTE], true))) {
                $open[] = $index;
            } elseif ((')' === $token || ']' === $token || '}' === $token) && [] !== $open) {
                $closers[(int) array_pop($open)] = $index;
            }
        }

        return TokenGuards::regions($tokens, $offsets, $closers, $content);
    }

    private static function parser(string $content): GuardRegions
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse($content);
        self::assertIsArray($ast);

        return PhpParserGuards::regions($ast, $content);
    }
}
