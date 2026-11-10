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

namespace PHPRegex\Tests\Unit\Linter;

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Resolution\ArgumentState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern argument of a call is an occurrence of its own, beside the
 * occurrences of the patterns it holds: it holds no pattern, and says what
 * is known of the argument, where it stands and how the source writes it.
 */
final class PatternOccurrenceArgumentTest extends TestCase
{
    private const EXPRESSION = "'/^' . preg_quote(\$word, '/') . '$/'";

    /**
     * The state is the string of the closed set, not the case: the
     * occurrence stays plain data.
     *
     * @return iterable<string, array{state: string}>
     */
    public static function provideStates(): iterable
    {
        foreach (ArgumentState::cases() as $state) {
            yield $state->value => ['state' => $state->value];
        }
    }

    #[Test]
    #[DataProvider('provideStates')]
    public function test_argument_holds_its_state_its_place_and_its_expression(string $state): void
    {
        $argument = PatternOccurrence::argument($state, '/app/src/Check.php', 12, 20, 345, 'preg_match()', self::EXPRESSION);

        $this->assertSame($state, $argument->state);
        $this->assertSame('/app/src/Check.php', $argument->file);
        $this->assertSame(12, $argument->line);
        $this->assertSame(20, $argument->column);
        $this->assertSame(345, $argument->fileOffset);
        $this->assertSame('preg_match()', $argument->source);
        $this->assertSame(self::EXPRESSION, $argument->expression);
    }

    /**
     * It is told from every other occurrence: no pattern, and nothing of a
     * file read, a file left unread or a file the tokenizer read.
     */
    #[Test]
    public function test_argument_holds_no_pattern_and_stands_for_no_file(): void
    {
        $argument = PatternOccurrence::argument(ArgumentState::Opaque->value, '/app/src/Check.php', 12, 20, 345, 'preg_match()', '$re');

        $this->assertSame('', $argument->pattern);
        $this->assertNull($argument->displayPattern);
        $this->assertFalse($argument->isIgnored);
        $this->assertNull($argument->ignores);
        $this->assertNull($argument->unread);
        $this->assertNull($argument->parserFallback);
        $this->assertNull($argument->guard);
        $this->assertSame([], $argument->targets);
    }

    /**
     * The expression is kept byte for byte: line breaks, multi-byte text
     * and a NUL byte included.
     *
     * @return iterable<string, array{expression: string}>
     */
    public static function provideExpressions(): iterable
    {
        yield 'a variable' => ['expression' => '$re'];
        yield 'over several lines' => ['expression' => "'/^' .\n            \$word .\n        '$/'"];
        yield 'multi-byte text' => ['expression' => "'/^été' . \$word"];
        yield 'a NUL byte' => ['expression' => "\"/a\0\" . \$word"];
        yield 'carriage returns' => ['expression' => "'/a' .\r\n\$word"];
    }

    #[Test]
    #[DataProvider('provideExpressions')]
    public function test_argument_keeps_its_expression_byte_for_byte(string $expression): void
    {
        $this->assertSame($expression, PatternOccurrence::argument(ArgumentState::RawShell->value, '/app/src/Check.php', 1, 1, 0, 'preg_match()', $expression)->expression);
    }

    /**
     * @return iterable<string, array{other: PatternOccurrence}>
     */
    public static function provideOtherOccurrences(): iterable
    {
        yield 'a pattern an extractor found' => ['other' => PatternOccurrence::found('/a+/', '/app/src/Check.php', 12, 9, 'check()')];
        yield 'a pattern the library built' => ['other' => new PatternOccurrence('/a+/', '/app/src/Check.php', 12, 'preg_match()', column: 9, fileOffset: 120)];
        yield 'a file read' => ['other' => PatternOccurrence::scanned('/app/src/Check.php', [])];
        yield 'a file left unread' => ['other' => PatternOccurrence::unread('/app/src/Check.php', 'Not linted: the file could not be read.')];
        yield 'a file the tokenizer read' => ['other' => PatternOccurrence::parserFallback('/app/src/Check.php', 'Syntax error')];
    }

    /**
     * found() and the other occurrences are unchanged: none holds a state
     * or an expression, which is how an argument is told from them.
     */
    #[Test]
    #[DataProvider('provideOtherOccurrences')]
    public function test_only_an_argument_holds_a_state_and_an_expression(PatternOccurrence $other): void
    {
        $argument = PatternOccurrence::argument(ArgumentState::Constant->value, '/app/src/Check.php', 12, 9, 120, 'preg_match()', "'/a+/'");

        $this->assertSame('constant', $argument->state);
        $this->assertSame("'/a+/'", $argument->expression);

        $this->assertNull($other->state);
        $this->assertNull($other->expression);
    }

    /**
     * A marker on its line, a guard around it: the copies the run makes
     * of an occurrence keep what makes it an argument.
     */
    #[Test]
    public function test_a_copy_of_an_argument_keeps_its_state_and_its_expression(): void
    {
        $argument = PatternOccurrence::argument(ArgumentState::QuotedShell->value, '/app/src/Check.php', 12, 20, 345, 'preg_match()', self::EXPRESSION);
        $guard = TargetConstraint::phpFrom(80200, null);

        $ignored = $argument->ignoredWhole();
        $this->assertTrue($ignored->isIgnored);

        $guarded = $argument->guardedBy($guard);
        $this->assertNotNull($guarded->guard);

        $analysed = $ignored->analysed();
        $this->assertFalse($analysed->isIgnored);

        foreach (['ignoredWhole()' => $ignored, 'guardedBy()' => $guarded, 'guardedBy(null)' => $argument->guardedBy(null), 'analysed()' => $analysed] as $name => $copy) {
            $this->assertSame('quoted_shell', $copy->state, $name);
            $this->assertSame(self::EXPRESSION, $copy->expression, $name);
            $this->assertSame('', $copy->pattern, $name);
            $this->assertSame('/app/src/Check.php', $copy->file, $name);
            $this->assertSame(12, $copy->line, $name);
            $this->assertSame(20, $copy->column, $name);
            $this->assertSame(345, $copy->fileOffset, $name);
            $this->assertSame('preg_match()', $copy->source, $name);
        }
    }

    /**
     * The new members come after the existing ones, each with a default:
     * an occurrence built as before the arguments existed holds none.
     */
    #[Test]
    public function test_the_members_of_an_argument_follow_the_existing_ones_with_defaults(): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(PatternOccurrence::class, '__construct'))->getParameters(),
        );

        $this->assertSame(
            ['pattern', 'file', 'line', 'source', 'displayPattern', 'location', 'isIgnored', 'column', 'fileOffset', 'unread', 'parserFallback', 'ignores', 'guard', 'targets', 'argumentOffset', 'argumentLength'],
            \array_slice($parameters, 0, 16),
        );
        $this->assertContains('state', \array_slice($parameters, 16));
        $this->assertContains('expression', \array_slice($parameters, 16));

        foreach (\array_slice((new \ReflectionMethod(PatternOccurrence::class, '__construct'))->getParameters(), 16) as $parameter) {
            $this->assertTrue($parameter->isDefaultValueAvailable(), $parameter->getName());
        }
    }

    /**
     * argument() is the library's, as scanned() is: an extractor of one's
     * own builds its occurrences with found().
     */
    #[Test]
    public function test_argument_is_internal(): void
    {
        $this->assertStringContainsString('@internal', (string) (new \ReflectionMethod(PatternOccurrence::class, 'argument'))->getDocComment());
    }
}
