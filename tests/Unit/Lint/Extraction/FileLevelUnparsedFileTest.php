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
use PHPRegex\Linter\Extraction\ArgumentAwareInterface;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file PHP does not parse runs nothing, and is read all the same: the
 * parser strategy hands it to the tokenizer, which reads its tokens as
 * those of any other file. A variable of its file level is read under
 * the rule FileLevelImplicitCodeTest holds, and what makes the file
 * unreadable to PHP opens no way around the rule: a bracket, a
 * declaration, a body, a string or a heredoc left open, a bracket closed
 * that nothing opened, a statement cut short. What is off the list
 * between the assignment and the read is still seen there, and a
 * variable the cut statement was assigning is proved no more.
 *
 * PHP 8.4 answers each file with a parse error: token_get_all() with
 * TOKEN_PARSE throws.
 *
 * Every case runs through both strategies: the tokenizer, and the parser
 * strategy where the parser is installed, which reports the file as one
 * it could not read and gives what the tokenizer gives.
 *
 * @phpstan-type Read array{arguments: list<array{state: string, expression: string}>, patterns: list<string>, refused: int}
 */
final class FileLevelUnparsedFileTest extends TestCase
{
    use TemporaryProject;

    /**
     * Something off the list stands between the assignment and the read,
     * beside what PHP does not parse, or is what PHP does not parse.
     *
     * The first three rows cut an assignment short, by a declaration, by
     * the brace that opens a block and by the one that closes a body:
     * the variable it was assigning an array holds no string any more,
     * and joining it to one is off the list.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesHoldingSomethingOffTheList(): iterable
    {
        yield 'an assignment cut by a declaration' => ['code' => self::between("\$label = 'a';\n\$label = ['b']\nfunction helper(): void\n{\n}\n\$title = \$label . 'y';")];
        yield 'an assignment cut by the brace of a block, its variable joined in the block' => ['code' => "<?php\n\n\$re = '/file-level/';\n\$label = 'a';\n\$label = ['b'] {\n    \$title = \$label . 'y';\n    preg_match(\$re, \$subject);\n}\n"];
        yield 'an assignment cut by the end of a body, its variable joined after it' => ['code' => self::between("\$label = 'a';\nif (\$argc > 1) {\n    \$label = ['b']\n}\n\$title = \$label . 'y';")];

        yield 'a function cut by a closing tag' => ['code' => self::between("function helper() ?>\n<?php")];
        yield 'a function without a body' => ['code' => self::between('function helper();')];
        yield 'a function whose parentheses are left open' => ['code' => self::between('function helper(')];
        yield 'a function whose body is left open' => ['code' => self::between("function helper(): void\n{")];
        yield 'a class whose body is left open' => ['code' => self::between("final class Helper\n{\n    public function run(): void\n    {\n    }")];

        yield 'a heredoc left open, holding the call' => ['code' => "<?php\n\n\$re = '/file-level/';\n\$label = <<<TXT\n    {\$object->info(preg_match(\$re, \$subject))}\n"];
        yield 'a string left open, holding the call' => ['code' => "<?php\n\n\$re = '/file-level/';\n\$label = \"x {\$object->info(preg_match(\$re, \$subject))}\n"];

        yield 'a parenthesis closed that nothing opened' => ['code' => self::between("\$label = 'a');")];
        yield 'a square bracket closed that nothing opened' => ['code' => self::between("\$label = 'a'];")];
        yield 'a brace closed that nothing opened' => ['code' => self::between("\$label = 'a';\n}")];
        yield 'a parenthesis closed that nothing opened, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\n\$found = ('a' === 'b')) && preg_match(\$re, \$subject);\n"];
        yield 'a square bracket closed that nothing opened, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\n\$found = 'a' === 'b' ] && preg_match(\$re, \$subject);\n"];
        yield 'a brace closed that nothing opened, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\n\$found = 'a' === 'b' } && preg_match(\$re, \$subject);\n"];
        // An if and an else begin a statement, and nothing else.
        yield 'an if written before the statement has ended' => ['code' => self::between("\$label = 'x' if (\$argc > 1) {\n}")];
        yield 'an else written before the statement has ended' => ['code' => self::between("\$label = 'x' else {\n}")];
        // A comparison with nothing is none of those that run no code.
        yield 'a comparison left without its second operand' => ['code' => self::between("if ((\$object == )) {\n    \$label = 'a';\n}")];
        yield 'a condition left open, holding the call in its body' => ['code' => "<?php\n\n\$re = '/file-level/';\nif (\$argc > 1 {\n    preg_match(\$re, \$subject);\n}\n"];

        // What follows the stray bracket is read as after any statement.
        yield 'echo after an array left open in a body' => ['code' => self::between("if (\$argc > 1) {\n    \$options = ['a', 'b'\n}\necho \$object;")];
        yield 'echo after a parenthesis left open in a body' => ['code' => self::between("if (\$argc > 1) {\n    \$label = ('a' . 'b'\n}\necho \$object;")];
        yield 'echo after a parenthesis closed that nothing opened, written before the assignment' => ['code' => "<?php\n\n\$label = 'a');\n\$re = '/file-level/';\necho \$object;\npreg_match(\$re, \$subject);\n"];
        yield 'echo after a brace closed that nothing opened, written before the assignment' => ['code' => "<?php\n\n}\n\$re = '/file-level/';\necho \$object;\npreg_match(\$re, \$subject);\n"];
        // The declaration never ends: the assignment, the echo and the
        // read are written after its keyword, at the file level.
        yield 'echo between, after a function whose parentheses are left open' => ['code' => "<?php\n\nfunction helper(\n\$re = '/file-level/';\necho \$object;\npreg_match(\$re, \$subject);\n"];
    }

    #[Test]
    #[DataProvider('provideFilesHoldingSomethingOffTheList')]
    public function test_a_file_php_does_not_parse_reads_no_variable_across_what_the_list_does_not_hold(string $code): void
    {
        $read = $this->readByBoth($code);

        $this->assertSame([['state' => 'opaque', 'expression' => '$re']], $read['arguments']);
        $this->assertSame([], $read['patterns']);
    }

    /**
     * What PHP does not parse stands after the read, before the
     * assignment, or between the two with nothing else: reading the file
     * ends, both strategies anchor the one argument, and they agree on
     * what they read of it.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesToReadToTheirEnd(): iterable
    {
        yield 'a function whose parentheses are left open, after the read' => ['code' => "<?php\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\nfunction ("];
        yield 'a function whose body is left open, after the read' => ['code' => "<?php\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\nfunction helper(): void\n{"];
        yield 'a heredoc left open, after the read' => ['code' => "<?php\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n\$label = <<<TXT\n    never closed\n"];
        yield 'a string left open, after the read' => ['code' => "<?php\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n\$label = \"x \$object;\n"];
        yield 'brackets closed that nothing opened, after the read' => ['code' => "<?php\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n}\n)\n]\n"];
        yield 'a brace closed that nothing opened, before the assignment' => ['code' => "<?php\n\n}\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n"];
        yield 'an array left open in a body, between' => ['code' => self::between("if (\$argc > 1) {\n    \$options = ['a', 'b'\n}")];
        yield 'an array left open, between' => ['code' => self::between("\$options = ['a', 'b';")];
        yield 'a call left open, holding the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nvar_dump(preg_match(\$re, \$subject);\n"];
        yield 'the body of an if left open, holding the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nif (\$argc > 1) {\n    preg_match(\$re, \$subject);\n"];
    }

    #[Test]
    #[DataProvider('provideFilesToReadToTheirEnd')]
    public function test_a_file_php_does_not_parse_is_read_to_its_end_by_both_strategies(string $code): void
    {
        $read = $this->readByBoth($code);

        $this->assertCount(1, $read['arguments']);
        $this->assertSame('$re', $read['arguments'][0]['expression']);
        // Read, it holds the one string the file assigns it.
        $this->assertSame('constant' === $read['arguments'][0]['state'] ? ['/file-level/'] : [], $read['patterns']);
    }

    /**
     * A file where the variable is the whole argument, and something is
     * written between its assignment and the read.
     */
    private static function between(string $between): string
    {
        return "<?php\n\n\$re = '/file-level/';\n".$between."\npreg_match(\$re, \$subject);\n";
    }

    /**
     * What both strategies read of the file: the pattern arguments, each
     * with its state, and the patterns.
     *
     * @return Read
     */
    private function readByBoth(string $code): array
    {
        $this->assertFalse(self::parses($code), 'PHP parses the file.');

        $file = $this->makeProject(['page.php' => $code]).'/page.php';

        $fromTokens = $this->readWith(new TokenBasedExtractionStrategy(), $file);
        $this->assertSame(0, $fromTokens['refused'], 'The tokenizer refuses no file.');

        if (class_exists(ParserFactory::class)) {
            $fromParser = $this->readWith(new PhpParserExtractionStrategy(), $file);
            $this->assertSame(1, $fromParser['refused'], 'The parser strategy reports the file once.');
            $this->assertSame($fromTokens['arguments'], $fromParser['arguments'], 'The parser strategy states the arguments of the file otherwise than the tokenizer.');
            $this->assertSame($fromTokens['patterns'], $fromParser['patterns'], 'The parser strategy reads the file otherwise than the tokenizer.');
        }

        return $fromTokens;
    }

    /**
     * Whether PHP parses the code: the tokenizer, asked to parse, throws
     * on what the engine refuses.
     */
    private static function parses(string $code): bool
    {
        try {
            return [] !== token_get_all($code, \TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }
    }

    /**
     * @return Read
     */
    private function readWith(ExtractorInterface $strategy, string $file): array
    {
        $this->assertInstanceOf(ArgumentAwareInterface::class, $strategy);

        $read = ['arguments' => [], 'patterns' => [], 'refused' => 0];
        foreach ($strategy->extractWithArguments([$file]) as $occurrence) {
            $this->assertInstanceOf(PatternOccurrence::class, $occurrence);
            $this->assertNull($occurrence->unread, 'The file is read.');
            if (null !== $occurrence->parserFallback) {
                $read['refused']++;
            } elseif (null === $occurrence->state) {
                $read['patterns'][] = $occurrence->pattern;
            } else {
                $read['arguments'][] = ['state' => $occurrence->state, 'expression' => (string) $occurrence->expression];
            }
        }

        return $read;
    }
}
