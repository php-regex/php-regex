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
 * A file PHP does not parse is read by its tokens, under the rule
 * FileLevelImplicitCodeTest holds, and FileLevelUnparsedFileTest shows
 * that what makes it unreadable to PHP opens no way around the rule.
 * These are the statements PHP refuses one token at a time: a value
 * written beside another, a comma, an arrow or an assignment where none
 * may stand, a bracket closed by another, a keyword of a declaration
 * with nothing to declare, a statement that is not ended. Each is off
 * the list where it stands, proves nothing of the variable it was
 * assigning, and, when it never ends, holds what is written after it:
 * an assignment written there is no statement of its own.
 *
 * What a statement PHP refuses leaves closed is closed: once a body, a
 * declaration or a statement has ended, what follows is read as after
 * any other.
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
final class FileLevelUnparsedStatementTest extends TestCase
{
    use TemporaryProject;

    /**
     * Each row is written between the assignment of $re and its read.
     * The first rows assign $label what is no string of the list, then
     * join it: nothing is proved of it.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideStatementsWrittenBetween(): iterable
    {
        yield 'two numbers side by side, their variable joined after' => ['between' => "\$label = 1 2;\n\$title = \$label . 'y';"];
        yield 'two booleans side by side, their variable joined after' => ['between' => "\$label = true false;\n\$title = \$label . 'y';"];
        yield 'a string beside array()' => ['between' => "\$label = 'a' array('b');"];
        yield 'a keyword of a declaration alone in parentheses' => ['between' => '$format = (function);'];
        yield 'a keyword of a declaration alone' => ['between' => '$format = function;'];
        yield 'the keyword of a class alone in an array' => ['between' => '$shape = [class];'];
    }

    #[Test]
    #[DataProvider('provideStatementsWrittenBetween')]
    public function test_a_statement_php_refuses_between_the_assignment_and_the_read_leaves_the_variable_unread(string $between): void
    {
        $this->assertUnread("<?php\n\n\$re = '/file-level/';\n".$between."\npreg_match(\$re, \$subject);\n");
    }

    /**
     * Each row is the statement that holds the read, written after the
     * assignment of $re.
     *
     * @return iterable<string, array{statement: string}>
     */
    public static function provideStatementsHoldingTheRead(): iterable
    {
        yield 'a comma in parentheses, before the call' => ['statement' => "\$found = ('a', preg_match(\$re, \$subject));"];
        yield 'an arrow in parentheses, before the call negated' => ['statement' => "\$found = ('k' => !preg_match(\$re, \$subject));"];
        yield 'a ";" in parentheses, before the call negated' => ['statement' => "\$found = ('a'; !preg_match(\$re, \$subject));"];
        yield 'a declare without its parentheses, before the call negated' => ['statement' => 'declare !preg_match($re, $subject);'];
        yield 'an assignment to a string, of the call' => ['statement' => "'label' = preg_match(\$re, \$subject);"];
        yield 'an assignment to a number, of the call' => ['statement' => '1 = preg_match($re, $subject);'];
        yield 'an attribute holding the call negated' => ['statement' => "#[!preg_match(\$re, \$subject)]\nfunction helper(): void\n{\n}"];
    }

    #[Test]
    #[DataProvider('provideStatementsHoldingTheRead')]
    public function test_a_statement_php_refuses_holding_the_read_leaves_the_variable_unread(string $statement): void
    {
        $this->assertUnread("<?php\n\n\$re = '/file-level/';\n".$statement."\n");
    }

    /**
     * Each row is written before the assignment of $re, and never ends,
     * or ends where the statement of the assignment does: the
     * assignment is written in it, and is no statement of its own.
     *
     * @return iterable<string, array{before: string}>
     */
    public static function provideStatementsThatHoldTheAssignment(): iterable
    {
        yield 'a function whose body is left open' => ['before' => "function helper(): void\n{"];
        yield 'a function whose parentheses are left open' => ['before' => 'function helper('];
        yield 'a function whose attribute is left open' => ['before' => "function helper(#[Marker \$value) {\n}"];
        yield 'a class whose body is left open' => ['before' => "final class Helper\n{\n    public function run(): void\n    {\n    }"];
        yield 'an interface whose body is left open' => ['before' => "interface Shape\n{"];
        yield 'a trait whose body is left open' => ['before' => "trait Shared\n{"];
        yield 'an enum whose body is left open' => ['before' => "enum Suit\n{"];
        yield 'a closure whose body is left open' => ['before' => '$format = function () {'];
        yield 'a closure written against its parentheses, whose body is left open' => ['before' => '$format = function() {'];
        yield 'an anonymous class whose body is left open' => ['before' => '$shape = new class {'];
        yield 'a closure whose statement is not ended' => ['before' => "\$format = function(Rewriter \$object) {\n}"];
        yield 'a brace closed that nothing opened, in a statement that is not ended' => ['before' => "\$label = 'a' }"];
        yield 'an array closed by a parenthesis' => ['before' => "\$options = ['a');"];
        yield 'a parenthesis closed by a square bracket' => ['before' => "\$label = ('a'];"];
    }

    #[Test]
    #[DataProvider('provideStatementsThatHoldTheAssignment')]
    public function test_an_assignment_written_in_a_statement_php_refuses_is_no_statement_of_its_own(string $before): void
    {
        $this->assertUnread("<?php\n\n".$before."\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n");
    }

    /**
     * $label is assigned in a statement PHP refuses, before the
     * assignment of $re, and joined after it: the statement proved
     * nothing.
     */
    #[Test]
    public function test_a_variable_assigned_before_a_parenthesis_nothing_opened_is_not_proved(): void
    {
        $this->assertUnread("<?php\n\n\$label = 'a');\n\$re = '/file-level/';\n\$title = \$label . 'y';\npreg_match(\$re, \$subject);\n");
    }

    /**
     * Each row is written before the assignment of $re, and has ended
     * where the assignment starts: the bracket left open in a body ends
     * with the body, and a declaration without a body where a ";" or a
     * closing tag ends it.
     *
     * @return iterable<string, array{before: string}>
     */
    public static function provideStatementsEndedBeforeTheAssignment(): iterable
    {
        yield 'an array left open in a body' => ['before' => "if (\$argc > 1) {\n    \$options = ['a', 'b'\n}"];
        yield 'array() left open in a body' => ['before' => "if (\$argc > 1) {\n    \$options = array('a', 'b'\n}"];
        yield 'an array left open in array(), in a body' => ['before' => "if (\$argc > 1) {\n    \$options = array(['a', 'b'\n}"];
        yield 'a function without a body' => ['before' => 'function helper();'];
        yield 'a closure without a body' => ['before' => '$format = function ();'];
        yield 'a function cut by a closing tag' => ['before' => "function helper() ?>\n<?php"];
        yield 'a closure cut by a closing tag' => ['before' => "\$format = function () ?>\n<?php"];
    }

    #[Test]
    #[DataProvider('provideStatementsEndedBeforeTheAssignment')]
    public function test_a_statement_php_refuses_ended_before_the_assignment_leaves_the_variable_read(string $before): void
    {
        $read = $this->readByBoth("<?php\n\n".$before."\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n");

        $this->assertSame([['state' => 'constant', 'expression' => '$re']], $read['arguments']);
        $this->assertSame(['/file-level/'], $read['patterns']);
    }

    /**
     * The read is the negated condition of an if, written after the
     * body that left an array open: the array is closed with the body,
     * and a negation after it is the beginning of a statement as
     * anywhere else.
     */
    #[Test]
    public function test_an_array_left_open_in_a_body_holds_nothing_written_after_the_body(): void
    {
        $read = $this->readByBoth("<?php\n\nif (\$argc > 1) {\n    \$options = ['a', 'b'\n}\n\$re = '/file-level/';\nif (!preg_match(\$re, \$subject)) {\n    \$found = false;\n}\n");

        $this->assertSame([['state' => 'constant', 'expression' => '$re']], $read['arguments']);
        $this->assertSame(['/file-level/'], $read['patterns']);
    }

    private function assertUnread(string $code): void
    {
        $read = $this->readByBoth($code);

        $this->assertSame([['state' => 'opaque', 'expression' => '$re']], $read['arguments']);
        $this->assertSame([], $read['patterns']);
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
