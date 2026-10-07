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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Explain\AsciiTreeRenderer;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The body of "(*pla:...)" and the other alphabetic assertions is read like
 * any group body: a ")" that is escaped, quoted by \Q...\E, inside a class
 * or inside a "(?#...)" comment does not close it, and a body that never
 * closes is refused (pcre2test 10.40 and PHP on 10.48 agree on the offsets).
 */
final class AlphaAssertionBodyTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideBodies')]
    public function test_validate_reads_the_body_as_pcre_does(string $pattern, array $subjects): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), \sprintf('%s should compile.', $pattern));

        $regex = Regex::create(['cache' => null]);
        $result = $regex->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));

        $compiled = $regex->parse($pattern)->accept(new PatternPrinter());
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s, which disagrees on %s.', $pattern, $compiled, json_encode($subject)));
        }
    }

    #[Test]
    #[DataProvider('provideUnclosedBodies')]
    public function test_validate_refuses_a_body_that_never_closes(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertStringContainsStringIgnoringCase('missing closing parenthesis', (string) $result->error, $pattern);
        $this->assertSame(ErrorCode::GroupUnclosed, $result->errorCode, $pattern);
    }

    #[Test]
    #[DataProvider('provideUnclosedBodiesFullOfComments')]
    public function test_lexer_reads_an_unclosed_body_full_of_comments_in_linear_time(string $pattern): void
    {
        // A comment could be read two ways, as a comment or as a nested
        // group: a body that never closes backtracked through every choice.
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertStringNotContainsString('Backtrack limit', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnclosedBodiesFullOfComments(): iterable
    {
        yield 'twenty comments' => ['pattern' => '/(*pla:'.str_repeat('(?#)', 20).'a\\)/'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideBodies(): iterable
    {
        yield 'escaped closing parenthesis' => ['pattern' => '/^(*pla:\\))./', 'subjects' => [')', 'a']];
        yield 'closing parenthesis in a class' => ['pattern' => '/^(*pla:[)])./', 'subjects' => [')', 'a']];
        yield 'bracket first in a class' => ['pattern' => '/^(*pla:[]a)])./', 'subjects' => [']', ')', 'b']];
        yield 'escaped bracket in a class' => ['pattern' => '/^(*pla:[\\])])./', 'subjects' => [']', ')', 'b']];
        yield 'POSIX class then parenthesis' => ['pattern' => '/^(*pla:[[:alpha:])])./', 'subjects' => ['a', ')', '1']];
        yield 'quoted closing parenthesis' => ['pattern' => '/^(*pla:\\Q)\\E)./', 'subjects' => [')', 'a']];
        yield 'quoted bracket in a class' => ['pattern' => '/^(*pla:[\\Q]\\E)])./', 'subjects' => [']', ')', 'a']];
        yield 'comment holding a parenthesis' => ['pattern' => '/^(*pla:(?#()a)./', 'subjects' => ['a', 'b']];
        yield 'parenthesis in an x-mode comment' => ['pattern' => "/^(*pla:a#(\n)./x", 'subjects' => ['ab', 'b']];
        yield 'bracket in an x-mode comment' => ['pattern' => "/^(*pla:a # [\n)./x", 'subjects' => ['ab', 'b']];
        yield 'parenthesis in a comment under an inline x' => ['pattern' => "/(?x)^(*pla:a#(\n)./", 'subjects' => ['ab', 'b']];
        yield 'x turned off before the body' => ['pattern' => '/(?-x)^(*pla:a # b)./x', 'subjects' => ['a # b', 'ab']];
        yield 'hash in a class under x' => ['pattern' => '/^(*pla:[#)])./x', 'subjects' => ['#', ')', 'a']];
        yield 'control character of an opening parenthesis' => ['pattern' => '/^(*pla:\\c()./', 'subjects' => ['h', 'a']];
        yield 'control character of a closing bracket in a class' => ['pattern' => '/^(*pla:[\\c]])./', 'subjects' => ["\x1d", ']']];
        yield 'callout string holding a closing parenthesis' => ['pattern' => '/^(*pla:(?C"a)b"))a/', 'subjects' => ['a', 'b']];
        yield 'callout string in backquotes' => ['pattern' => '/^(*pla:(?C`a)`))a/', 'subjects' => ['a', 'b']];
        yield 'callout string holding an opening parenthesis' => ['pattern' => '/^(*pla:(?C"a(b"))a/', 'subjects' => ['a', 'b']];
        yield 'mark name holding an opening parenthesis' => ['pattern' => '/^(*pla:(*MARK:a(b))a/', 'subjects' => ['a', 'b']];
        yield 'short mark name holding an opening parenthesis' => ['pattern' => '/^(*pla:(*:a(b))a/', 'subjects' => ['a', 'b']];
        yield 'atomic group' => ['pattern' => '/^(*atomic:\\))/', 'subjects' => [')', 'a']];
        yield 'script run' => ['pattern' => '/^(*script_run:[)])/', 'subjects' => [')', 'a']];
        yield 'caret then bracket first in a class' => ['pattern' => '/^(*pla:[^]])./', 'subjects' => [']', 'a', ')']];
        yield 'caret, bracket and parenthesis in a class' => ['pattern' => '/^(*pla:[^])])./', 'subjects' => ['a', ')', ']']];
        yield 'POSIX opener without its closer' => ['pattern' => '/^(*pla:[[:a:)])./', 'subjects' => [':', 'a', ')', 'b']];
        yield 'class inside nested groups' => ['pattern' => '/^(*pla:(a(b[)])c))./', 'subjects' => ['ab)c', 'abc']];
        yield 'class in a short lookahead' => ['pattern' => '/^(?*[)])./', 'subjects' => [')', 'a']];
        yield 'escaped backslash closing a class' => ['pattern' => '/^(*pla:[a\\\\])./', 'subjects' => ['\\', 'a', 'b']];
        // A body inside a body inside a body, each followed by text: where
        // the innermost one ends is found once and used at every level.
        yield 'three nested bodies with text after each' => ['pattern' => '/^(*pla:(*pla:(*pla:a)a)a)./', 'subjects' => ['a', 'b']];
        yield 'POSIX class then the closing bracket' => ['pattern' => '/^(*pla:[[:alpha:]])./', 'subjects' => ['a', '1']];
        yield 'two POSIX classes in a row' => ['pattern' => '/^(*pla:[[:alpha:][:digit:])])./', 'subjects' => ['a', '1', ')', '-']];
        yield 'bracket first then a POSIX class' => ['pattern' => '/^(*pla:[][:alpha:])])./', 'subjects' => [']', 'a', ')', '1']];
        yield 'colons that open no POSIX class' => ['pattern' => '/^(*pla:[a:b:])./', 'subjects' => ['a', ':', 'b', 'c']];
        // "[:" met by a "]" at once is no POSIX class: that "]" closes the class.
        yield 'POSIX opener closed at once' => ['pattern' => '/^(*pla:[[:])a:]/', 'subjects' => ['[a:]', ':a:]', 'a:]']];
        yield 'bracket first then an escaped bracket' => ['pattern' => '/^(*pla:[]\\])])./', 'subjects' => [']', ')', 'a']];
        // "[:" then "[:" before any ":]" is no POSIX class: the first "]" closes the class.
        yield 'POSIX opener holding another opener' => ['pattern' => '/^(*pla:[[:[:])a]/', 'subjects' => ['[a]', ':a]', 'a]']];
        yield 'empty quote before the closing bracket' => ['pattern' => '/^(*pla:[a\\Q\\E])./', 'subjects' => ['a', 'b']];
        yield 'quoted bracket right before the closing bracket' => ['pattern' => '/^(*pla:[\\Q]\\E])./', 'subjects' => [']', 'a']];
        yield 'quote after a lone end of quote' => ['pattern' => '/^(*pla:[\\E\\Q)\\E])./', 'subjects' => [')', 'a']];
        yield 'control character of a closing bracket, more members after' => ['pattern' => '/^(*pla:[\\c])])./', 'subjects' => ["\x1d", ')', 'a']];
        // In a class, "(*pla:" and "(?*" are characters like any other.
        yield 'alphabetic opener inside a class' => ['pattern' => '/^[(*pla:a)]+$/', 'subjects' => ['(*pla:a)', 'b']];
        yield 'short lookahead opener inside a class' => ['pattern' => '/^[(?*a)]+$/', 'subjects' => ['(?*a)', 'b']];
    }

    /**
     * PCRE names the class it meets unclosed, "missing terminating ] for
     * character class" (or "\c at end of pattern" when the class ends on
     * "\c"), not the alphabetic assertion around it: the offset and the code
     * are the ones PCRE gives, read from the engine.
     */
    #[Test]
    #[DataProvider('provideUnclosedClassesInBodies')]
    public function test_validate_refuses_a_class_in_the_body_that_never_closes(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));
        $pcre = self::pcreError($pattern);
        $this->assertSame($offset, $pcre['offset'], 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertContains($result->errorCode?->value, PcreMessageCodes::CODES[$pcre['message']], \sprintf('%s: PCRE says "%s", the library %s (%s).', $pattern, $pcre['message'], $result->errorCode?->value, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedClassesInBodies(): iterable
    {
        yield 'parenthesis inside the class' => ['pattern' => '/(*pla:[a)/', 'offset' => 9];
        yield 'text after the parenthesis' => ['pattern' => '/(*pla:[a)b/', 'offset' => 10];
        yield 'bracket first and nothing after' => ['pattern' => '/(*pla:[]/', 'offset' => 8];
        yield 'caret and bracket first and nothing after' => ['pattern' => '/(*pla:[^]/', 'offset' => 9];
        yield 'POSIX class then the end' => ['pattern' => '/(*pla:[[:alpha:]/', 'offset' => 16];
        yield 'POSIX class that never closes' => ['pattern' => '/(*pla:[[:alpha:)/', 'offset' => 16];
        yield 'quoted bracket running to the end' => ['pattern' => '/(*pla:[\\Q]/', 'offset' => 10];
        yield 'quoted bracket then the end' => ['pattern' => '/(*pla:[\\Q]\\E/', 'offset' => 12];
        yield 'escaped bracket then the end' => ['pattern' => '/(*pla:[a\\]/', 'offset' => 10];
        yield 'control escape with nothing to take' => ['pattern' => '/(*pla:[\\c/', 'offset' => 9];
        yield 'class left open in a nested group' => ['pattern' => '/(*atomic:([a))/', 'offset' => 14];
        yield 'caret then an escaped bracket' => ['pattern' => '/(*pla:[^\\])/', 'offset' => 11];
        yield 'control character of the closing bracket' => ['pattern' => '/(*pla:[\\c])/', 'offset' => 11];
        yield 'bracket first then the closing parenthesis' => ['pattern' => '/(*pla:[])/', 'offset' => 9];
        yield 'bracket first then two closing parentheses' => ['pattern' => '/(*pla:[]))/', 'offset' => 10];
        yield 'caret and bracket first then the closing parenthesis' => ['pattern' => '/(*pla:[^])/', 'offset' => 10];
    }

    /**
     * An option set inside the body holds there as anywhere else: "x" from
     * "(?x)" to the end of its group, off again after "(?-x)" or "(?^)",
     * only inside "(?x:...)". Under "x" a "#" starts a comment that hides
     * the ")" after it. The verdict is the engine's.
     */
    #[Test]
    #[DataProvider('provideInlineExtendedModeInBodies')]
    public function test_validate_reads_an_inline_x_inside_the_body_as_pcre_does(string $pattern): void
    {
        $compiles = false !== @preg_match($pattern, '');

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame($compiles, $result->isValid, \sprintf('%s: PCRE %s it, the library %s.', $pattern, $compiles ? 'accepts' : 'refuses', $result->isValid ? 'accepts' : 'refuses ('.$result->error.')'));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideInlineExtendedModeInBodies(): iterable
    {
        yield 'x turned on hides the closing parenthesis' => ['pattern' => '/(*pla:(?x)#)a/'];
        yield 'same in a short lookahead' => ['pattern' => '/(?*(?x)#)a/'];
        yield 'xx turned on hides it too' => ['pattern' => '/(*pla:(?xx)#)a/'];
        yield 'x turned on in a later alternative' => ['pattern' => '/(*pla:a|(?x)#)/'];
        yield 'x turned on, a class, then a comment' => ['pattern' => '/(*pla:(?x)[[:[:]#)/'];
        yield 'x turned off inside the body' => ['pattern' => '/(?x)(*pla:(?-x)#)a/x'];
        yield 'x turned off under the x flag' => ['pattern' => '/(*pla:(?-x)#)/x'];
        yield 'caret clears x inside the body' => ['pattern' => '/(?x)(*pla:(?^)#)/'];
        yield 'caret clears xx inside the body' => ['pattern' => '/(?xx)(*pla:(?^)#(*ACCEPT))/'];
        yield 'comment ended by a newline in a nested body' => ['pattern' => "/^(*pla:(?x)(*pla:a#)\n)b)/"];
        yield 'comment ended by a newline' => ['pattern' => "/^(*pla:(?x)a#)\n)/"];
        // Read as the engine reads them already: kept as guards.
        yield 'x scoped to a group before the hash' => ['pattern' => '/(*pla:(?x:a)#)/'];
        yield 'x scoped to a group around the hash' => ['pattern' => '/(*pla:(?x:#))a)/'];
        yield 'x turned on and off at once' => ['pattern' => '/(*pla:(?x-x)#)/'];
        yield 'escaped hash under x' => ['pattern' => '/(*pla:(?x)\\#)/'];
        yield 'hash in a class under x' => ['pattern' => '/(*pla:(?x)[#)])/'];
        yield 'quoted hash under x' => ['pattern' => '/(*pla:(?x)\\Q#\\E)/'];
        yield 'comment group under the x flag' => ['pattern' => '/(*pla:(?#x)#)/x'];
        // A "#" comment ends at a newline of the pattern's convention: NEL
        // under (*ANY), as one byte or as U+0085 under (*UTF), U+2028 too;
        // a "\n" does not end it under (*CR).
        yield 'NEL byte ends a comment under (*ANY)' => ['pattern' => "/(*ANY)(?x)(*pla:a#)\x85)b/"];
        yield 'NEL byte ends no comment under the default newline' => ['pattern' => "/(?x)(*pla:a#)\x85)b/"];
        yield 'NEL ends a comment under (*UTF) and (*ANY)' => ['pattern' => "/(*UTF)(*ANY)(?x)(*pla:a#)\u{85})b/"];
        yield 'line separator ends a comment under (*UTF) and (*ANY)' => ['pattern' => "/(*UTF)(*ANY)(?x)(*pla:a#)\u{2028})b/"];
        yield 'carriage return ends a comment under (*CR)' => ['pattern' => "/(*CR)(?x)(*pla:a#)\r)b/"];
        yield 'line feed ends no comment under (*CR)' => ['pattern' => "/(*CR)(?x)(*pla:a#)\n)b/"];
    }

    /**
     * A run of "\p{" that no "}" closes is read once: each one does not look
     * again for a "}" that the one before it already did not find.
     */
    #[Test]
    public function test_lexer_reads_a_run_of_unclosed_properties_in_a_body_in_linear_time(): void
    {
        $this->assertLinearTime(
            static function (int $size): void {
                try {
                    (new Lexer())->tokenize('(*pla:'.str_repeat('\\p{', $size).')');
                } catch (LexerException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            8000,
            '(*pla: then \\p{',
        );
    }

    /**
     * Outside a body too: a run of "\p{" that no "}" closes is read once.
     */
    #[Test]
    public function test_lexer_reads_a_run_of_unclosed_properties_outside_a_body_in_linear_time(): void
    {
        $this->assertLinearTime(
            static function (int $size): void {
                (new Lexer())->tokenize(str_repeat('\\p{', $size));
            },
            16000,
            '\\p{',
        );
    }

    /**
     * Each "\p{" that no "}" closes is read as the escape "\p" then text,
     * which validation refuses where PCRE does ("malformed \P or \p
     * sequence" at 5 and 8).
     */
    #[Test]
    #[DataProvider('provideUnclosedPropertiesOutsideABody')]
    public function test_validate_refuses_an_unclosed_property_outside_a_body_where_pcre_does(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));
        $this->assertSame($offset, Regex::create(['cache' => null])->validate($pattern)->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedPropertiesOutsideABody(): iterable
    {
        yield 'property name running to the end' => ['pattern' => '/\\p{ab/', 'offset' => 5];
        yield 'two properties, neither closed' => ['pattern' => '/a\\P{x\\p{y/', 'offset' => 8];
    }

    /**
     * The body is cut into the same items the lexer reads anywhere: "\E" or
     * an empty "\Q\E" before the first member of a class, a "\p{...}" read
     * to its "}", a callout argument to the end PCRE gives it, an extended
     * class "(?[...])" whose "#" is no comment. The verdict is the engine's,
     * and so is the offset of a refusal.
     */
    #[Test]
    #[DataProvider('provideBodyItemsReadAsAnywhere')]
    public function test_validate_reads_each_body_item_as_the_lexer_does_anywhere(string $pattern, ?int $offset): void
    {
        $this->assertSame(null === $offset, false !== @preg_match($pattern, ''), \sprintf('Oracle: %s.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame(null === $offset, $result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: ?int}>
     */
    public static function provideBodyItemsReadAsAnywhere(): iterable
    {
        yield 'quote end before the first member' => ['pattern' => '/(*pla:[\\E])a])/', 'offset' => null];
        yield 'empty quote before the first member' => ['pattern' => '/(*pla:[\\Q\\E])a])/', 'offset' => null];
        yield 'quote end then caret' => ['pattern' => '/(*pla:[\\E^])a])/', 'offset' => null];
        // PCRE: "unknown property after \P or \p" at 16.
        yield 'property read to its brace under x' => ['pattern' => "/(*pla:(?x)\\p{L)}#)\n)/", 'offset' => 16];
        // PCRE: "unexpected character in (?[...]) extended character class" at 19.
        yield 'hash in an extended class under x' => ['pattern' => '/(*pla:(?x)(?[ [a] # ]))/', 'offset' => 19];
        // PCRE: "closing parenthesis for (?C expected" at 14.
        yield 'hash in a callout under x' => ['pattern' => '/(*pla:(?x)(?C1#))/', 'offset' => 14];
        // PCRE: "malformed \P or \p sequence" at 16.
        yield 'malformed property in a class under x' => ['pattern' => '/(*pla:(?x)[\\p{]#}])/', 'offset' => 16];
    }

    /**
     * A body that turns "x" off around a "#" hides no opener after it from
     * the main reading: each opener's body is read once. Measured when the
     * scan ignored the inline option: 500 units and their tail took 8.5 s.
     */
    #[Test]
    #[DataProvider('provideTailsAfterBodiesTurningXOff')]
    public function test_lexer_reads_bodies_turning_x_off_in_linear_time(string $unit, string $tailUnit, int $tailRatio, string $flags): void
    {
        $this->assertLinearTime(
            static function (int $size) use ($unit, $tailUnit, $tailRatio, $flags): void {
                try {
                    (new Lexer())->tokenize(str_repeat($unit, $size)."\n".str_repeat($tailUnit, $tailRatio * $size).('[' === $tailUnit[0] ? ']' : ''), $flags);
                } catch (LexerException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            250,
            $unit,
        );
    }

    /**
     * @return iterable<string, array{unit: string, tailUnit: string, tailRatio: int, flags: string}>
     */
    public static function provideTailsAfterBodiesTurningXOff(): iterable
    {
        yield 'escapes after closed bodies' => ['unit' => '(?*(?-x)#)', 'tailUnit' => '\\a', 'tailRatio' => 20, 'flags' => 'x'];
        yield 'a class after closed bodies' => ['unit' => '(?*(?-x)#)', 'tailUnit' => '[a', 'tailRatio' => 1, 'flags' => 'x'];
        yield 'escapes after bodies turning x back on' => ['unit' => '(?*(?-x)#(?x)', 'tailUnit' => '\\a', 'tailRatio' => 20, 'flags' => ''];
        yield 'property holding a hash and a parenthesis' => ['unit' => '(?*\\p{#)}', 'tailUnit' => '\\a', 'tailRatio' => 20, 'flags' => 'x'];
    }

    /**
     * A class left open in a body nested in another under an inline "x"
     * runs to the end of the pattern: the lexer refuses it there, where
     * PCRE does ("missing terminating ] for character class" at 12 and 13).
     */
    #[Test]
    #[DataProvider('provideClassesLeftOpenUnderAnInlineX')]
    public function test_lexer_refuses_a_class_left_open_under_an_inline_x(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match('/'.$pattern.'/', ''), \sprintf('/%s/ should not compile.', $pattern));

        $caught = null;

        try {
            (new Lexer())->tokenize($pattern);
        } catch (LexerException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(LexerException::class, $caught, \sprintf('%s leaves a class open but was read.', $pattern));
        $this->assertStringContainsString('Unclosed character class', $caught->getMessage());
        $this->assertSame($offset, $caught->getPosition(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideClassesLeftOpenUnderAnInlineX(): iterable
    {
        yield 'parenthesis in the class' => ['pattern' => '(?*(?x)(?*[(', 'offset' => 12];
        yield 'text after the parenthesis' => ['pattern' => '(?*(?x)(?*[(a', 'offset' => 13];
    }

    /**
     * No PHP pattern can end on a backslash, its delimiter would be escaped:
     * the bodies go to the lexer as they are. The offsets are pcre2test
     * 10.49's, the pattern given in hex: "\ at end of pattern", always at
     * the end of the pattern, so the code is the trailing backslash one,
     * not the unclosed body around it.
     *
     * The lexer reads no byte past the end on the way: under an error
     * handler that turns warnings into exceptions, as frameworks install,
     * the refusal is still the lexer's.
     */
    #[Test]
    #[DataProvider('provideBodiesEndingInABackslash')]
    public function test_lexer_refuses_a_body_ending_in_a_backslash(string $pattern, int $offset): void
    {
        $caught = null;
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            (new Lexer())->tokenize($pattern);
        } catch (LexerException $exception) {
            $caught = $exception;
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(LexerException::class, $caught, \sprintf('%s ends on a backslash but was read.', $pattern));
        $this->assertSame($offset, $caught->getPosition(), $pattern);
        $this->assertSame(ErrorCode::EscapeTrailingBackslash, $caught->getErrorCode(), \sprintf('%s: %s', $pattern, $caught->getMessage()));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideBodiesEndingInABackslash(): iterable
    {
        yield 'after text' => ['pattern' => '(*pla:a\\', 'offset' => 8];
        yield 'inside a class' => ['pattern' => '(*pla:[a\\', 'offset' => 9];
        yield 'inside a nested group' => ['pattern' => '(*pla:a(b\\', 'offset' => 10];
        yield 'after a POSIX class in a class' => ['pattern' => '(*pla:[[:alpha:]\\', 'offset' => 17];
        yield 'inside a nested body' => ['pattern' => '(*pla:(*pla:a\\', 'offset' => 14];
        yield 'after text in a short lookahead' => ['pattern' => '(?*a\\', 'offset' => 5];
        yield 'inside a class in a short lookahead' => ['pattern' => '(?*[a\\', 'offset' => 6];
        yield 'inside a nested group in a short lookahead' => ['pattern' => '(?*a(b\\', 'offset' => 7];
        yield 'inside a nested short lookahead' => ['pattern' => '(?*(?*a\\', 'offset' => 8];
        yield 'after a closed quote in a short lookahead' => ['pattern' => '(?*\\Qa\\E\\', 'offset' => 9];
    }

    /**
     * The library's own regexes run under at least PHP's default limits;
     * when that raise is refused they run under the caller's. A body item
     * the engine then gives up on is reported where it stands, as an
     * internal PCRE failure, never read as a body that does not close.
     *
     * Without the JIT, a callout string of 200 letters exhausts a
     * backtrack limit of 10 while the opener "(*pla:" passes it: the regexes
     * are compiled here first, in a process of their own, with the JIT off.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_lexer_reports_a_body_item_the_engine_gave_up_on(): void
    {
        $pattern = '(*pla:(?C"'.str_repeat('a', 200).'"))';
        $this->assertNotFalse(@preg_match('/'.$pattern.'/', ''), 'Oracle: PCRE compiles the body.');

        ini_set('pcre.jit', '0');
        $saved = (string) ini_get('pcre.backtrack_limit');
        $caught = null;
        LibraryPcre::useIniSetter(static fn (): false => false);

        try {
            ini_set('pcre.backtrack_limit', '10');

            try {
                (new Lexer())->tokenize($pattern);
            } catch (LexerException $exception) {
                $caught = $exception;
            }
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
            LibraryPcre::useIniSetter(null);
        }

        $this->assertInstanceOf(LexerException::class, $caught);
        $this->assertSame(ErrorCode::InternalPcreFailure, $caught->getErrorCode());
        $this->assertSame(6, $caught->getPosition());
        $this->assertStringContainsString('Backtrack limit exhausted', $caught->getMessage());

        $tokens = (new Lexer())->tokenize($pattern)->getTokens();
        $this->assertSame([TokenType::PcreVerb, TokenType::Eof], array_map(static fn ($token): TokenType => $token->type, $tokens));
        $this->assertSame(\strlen($pattern), $tokens[1]->position);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedBodies(): iterable
    {
        yield 'quote running to the end' => ['pattern' => '/(*pla:\\Qa)/', 'offset' => 10];
        yield 'quote after a letter running to the end' => ['pattern' => '/(*pla:a\\Q)/', 'offset' => 10];
        yield 'escaped parenthesis only' => ['pattern' => '/(*pla:a\\)/', 'offset' => 9];
        // "(?*" is "(*napla:" by another name: PCRE reports it unclosed at
        // the end of the pattern too (PHP on PCRE2 10.49).
        yield 'short lookahead with text' => ['pattern' => '/(?*a/', 'offset' => 4];
        yield 'short lookahead with nothing after it' => ['pattern' => '/(?*/', 'offset' => 3];
        yield 'short lookahead around a closed alphabetic one' => ['pattern' => '/(?*(*pla:a)/', 'offset' => 11];
        yield 'short lookahead whose x comment hides the parenthesis' => ['pattern' => "/(*CR)(?*b#c\n))/x", 'offset' => 14];
        yield 'short lookahead with an escaped parenthesis' => ['pattern' => '/(?*a\\)/', 'offset' => 6];
        yield 'short lookahead with a quote running to the end' => ['pattern' => '/(?*\\Qa)/', 'offset' => 7];
        yield 'short lookahead with an unclosed callout' => ['pattern' => '/(?*(?C/', 'offset' => 6];
        // Reported where PCRE does already: kept as guards.
        yield 'alphabetic lookahead around an open short one' => ['pattern' => '/(*pla:(?*a)/', 'offset' => 11];
        yield 'alphabetic lookahead with an unclosed callout' => ['pattern' => '/(*pla:(?C/', 'offset' => 9];
    }

    /**
     * A body that never closes is read once: the openers after it are not
     * each read again up to the end of the pattern. Twice the text costs
     * about twice the time, well under a second at these sizes, whatever
     * the items the body holds. A refusal is fine, as long as it comes fast.
     *
     * Measured when every opener was read to the end again: 1 000 then
     * 2 000 "(?*" took 0.48 s then 1.9 s, four times as long for twice the
     * text, and 10 000 took 17 s.
     */
    #[Test]
    #[DataProvider('provideChainsOfUnclosedBodies')]
    public function test_lexer_reads_a_chain_of_unclosed_bodies_in_linear_time(string $prefix, string $unit, string $flags): void
    {
        $this->assertLinearTime(
            static function (int $size) use ($prefix, $unit, $flags): void {
                try {
                    (new Lexer())->tokenize($prefix.str_repeat($unit, $size), $flags);
                } catch (LexerException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            1_000,
            (string) json_encode($prefix.$unit, \JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * @return iterable<string, array{prefix: string, unit: string, flags: string}>
     */
    public static function provideChainsOfUnclosedBodies(): iterable
    {
        yield 'short lookahead openers' => ['prefix' => '', 'unit' => '(?*', 'flags' => ''];
        yield 'quoted run in each body' => ['prefix' => '', 'unit' => '(?*\\Qa\\E', 'flags' => ''];
        yield 'escape in each body' => ['prefix' => '', 'unit' => '(?*\\.', 'flags' => ''];
        yield 'control escape in each body' => ['prefix' => '', 'unit' => '(?*\\cA', 'flags' => ''];
        yield 'comment in each body' => ['prefix' => '', 'unit' => '(?*(?#c)', 'flags' => ''];
        yield 'string callout in each body' => ['prefix' => '', 'unit' => '(?*(?C"s")', 'flags' => ''];
        yield 'verb in each body' => ['prefix' => '', 'unit' => '(?*(*MARK:a)', 'flags' => ''];
        yield 'text in each body' => ['prefix' => '', 'unit' => '(?*ab', 'flags' => ''];
        yield 'line comment under x in each body' => ['prefix' => '', 'unit' => "(?*#c\n", 'flags' => 'x'];
        yield 'class in each body' => ['prefix' => '', 'unit' => '(?*[a]', 'flags' => ''];
        yield 'class members of every kind in each body' => ['prefix' => '', 'unit' => '(?*[[:alpha:]\\Qa\\E\\cA\\]]', 'flags' => ''];
        yield 'caret and bracket first in each class' => ['prefix' => '', 'unit' => '(?*[^]a]', 'flags' => ''];
        yield 'nested group in each body' => ['prefix' => '', 'unit' => '(?*(', 'flags' => ''];
        yield 'byte mode body' => ['prefix' => '', 'unit' => "(?*\xFF", 'flags' => ''];
        yield 'POSIX opener hiding the end of each class' => ['prefix' => '', 'unit' => '(?*[[:[:])', 'flags' => ''];
        yield 'POSIX-like span with a bad name in each class' => ['prefix' => '', 'unit' => '(?*[[:!:]', 'flags' => ''];
        // Refused at the first opener, or read once, already: kept as guards.
        yield 'alphabetic assertion openers' => ['prefix' => '', 'unit' => '(*pla:', 'flags' => ''];
        yield 'names that never reach a colon' => ['prefix' => '', 'unit' => '(*ab', 'flags' => ''];
        yield 'POSIX openers in one class that never closes' => ['prefix' => '(?*[', 'unit' => '[:a', 'flags' => ''];
    }

    /**
     * The body of each nested assertion is read once, not once more for
     * every assertion around it. Measured when each level read its body
     * again: 500 then 1 000 nested "(*pla:a" parsed in 0.26 s then 0.96 s.
     * PCRE refuses parentheses nested deeper than 250, which the parser
     * leaves to validation: only the time to read the pattern counts here.
     */
    #[Test]
    #[DataProvider('provideNestedAssertionOpeners')]
    public function test_parse_reads_nested_alphabetic_assertions_in_linear_time(string $opener): void
    {
        $this->assertLinearTime(
            static function (int $depth) use ($opener): void {
                try {
                    Regex::create(['cache' => null])->parse('/'.str_repeat($opener.'a', $depth).str_repeat(')', $depth).'/');
                } catch (RegexException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            500,
            $opener,
        );
    }

    /**
     * @return iterable<string, array{opener: string}>
     */
    public static function provideNestedAssertionOpeners(): iterable
    {
        yield 'positive lookahead by its alphabetic name' => ['opener' => '(*pla:'];
        yield 'non-atomic lookahead' => ['opener' => '(?*'];
        yield 'atomic group by its alphabetic name' => ['opener' => '(*atomic:'];
    }

    /**
     * Text after each nested body, "(*pla:a(*pla:a b) b)": the end of each
     * body is found once, and holds for the shorter text of every body
     * around it. Measured when each level read its bodies again: 500 then
     * 1 000 levels parsed in 0.71 s then 3.7 s.
     */
    #[Test]
    #[DataProvider('provideNestedAssertionsFollowedByText')]
    public function test_parse_reads_nested_alphabetic_assertions_followed_by_text_in_linear_time(string $open, string $close): void
    {
        $this->assertLinearTime(
            static function (int $depth) use ($open, $close): void {
                try {
                    Regex::create(['cache' => null])->parse('/'.str_repeat($open, $depth).str_repeat($close, $depth).'/');
                } catch (RegexException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            500,
            $open.$close,
        );
    }

    /**
     * @return iterable<string, array{open: string, close: string}>
     */
    public static function provideNestedAssertionsFollowedByText(): iterable
    {
        yield 'positive lookahead by its alphabetic name' => ['open' => '(*pla:a', 'close' => 'b)'];
        yield 'non-atomic lookahead' => ['open' => '(?*a', 'close' => 'b)'];
        yield 'empty bodies' => ['open' => '(*pla:', 'close' => ')a'];
    }

    /**
     * The regex that reads a body item differs with the byte mode and with
     * "x", and is compiled once per process for each: a body read in one
     * mode never gets the regex of another. The bodies are read in turn
     * after the caches are emptied, each one PCRE compiles in its mode;
     * "(*pla:a#)\n)" holds a comment under "x" only, and "\xFF" is no UTF-8.
     *
     * @param array<string, array{pattern: string, flags: string, compilesWithoutX: bool}> $bodies
     */
    #[Test]
    #[DataProvider('provideBodiesInEveryMode')]
    public function test_lexer_reads_each_body_with_the_regex_of_its_mode(array $bodies): void
    {
        StaticCaches::clear();

        foreach ($bodies as $what => ['pattern' => $pattern, 'flags' => $flags, 'compilesWithoutX' => $compilesWithoutX]) {
            $this->assertNotFalse(@preg_match('/'.$pattern.'/'.$flags, ''), 'Oracle: '.$what.' compiles.');
            $this->assertSame($compilesWithoutX, false !== @preg_match('/'.$pattern.'/', ''), 'Oracle: '.$what.' without x.');

            $tokens = (new Lexer())->tokenize($pattern, $flags)->getTokens();

            $this->assertSame([TokenType::PcreVerb, TokenType::Eof], array_map(static fn ($token): TokenType => $token->type, $tokens), $what);
            $this->assertSame(\strlen($pattern), $tokens[1]->position, $what);
        }
    }

    /**
     * @return iterable<string, array{bodies: array<string, array{pattern: string, flags: string, compilesWithoutX: bool}>}>
     */
    public static function provideBodiesInEveryMode(): iterable
    {
        yield 'UTF-8 then bytes, without then with x' => ['bodies' => [
            'UTF-8 text' => ['pattern' => '(*pla:a)', 'flags' => '', 'compilesWithoutX' => true],
            'bytes' => ['pattern' => "(*pla:\xFF)", 'flags' => '', 'compilesWithoutX' => true],
            'UTF-8 text under x' => ['pattern' => "(*pla:a#)\n)", 'flags' => 'x', 'compilesWithoutX' => false],
            'bytes under x' => ['pattern' => "(*pla:\xFF#)\n)", 'flags' => 'x', 'compilesWithoutX' => false],
        ]];
    }

    /**
     * A pattern that is one alphabetic assertion compiles the body item
     * regex and no other: clearing the library's caches empties it too.
     * In a process of its own, so that nothing else registered the lexer's
     * caches first.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_clearing_the_caches_empties_the_body_item_regexes(): void
    {
        (new Lexer())->tokenize('(*pla:a)');
        $regexes = new \ReflectionProperty(Lexer::class, 'regexBodyItem');
        $this->assertNotSame([], $regexes->getValue());

        StaticCaches::clear();

        $this->assertSame([], $regexes->getValue());
    }

    /**
     * "(*pla:" is "(?=" by another name, "(*atomic:" is "(?>" and "(?*" is
     * "(*napla:": nested past the recursion limit, the alphabetic forms
     * are refused as nested "(?=" are. The body of each one is read as
     * deep as the group it stands for: the limit counts every level
     * around it, and 4 000 levels are refused at the same level as nested
     * "(?=" rather than run PHP out of stack.
     */
    #[Test]
    #[DataProvider('provideNestedAssertionOpeners')]
    public function test_parse_applies_the_recursion_limit_through_alphabetic_bodies(string $opener): void
    {
        $depth = Regex::DEFAULT_MAX_RECURSION_DEPTH + 76;
        $nested = static fn (string $open): string => '/'.str_repeat($open.'a', $depth).str_repeat(')', $depth).'/';

        $reference = null;

        try {
            Regex::create(['cache' => null])->parse($nested('(?='));
        } catch (RecursionLimitException $exception) {
            $reference = $exception;
        }
        $this->assertInstanceOf(RecursionLimitException::class, $reference, 'Nested "(?=" no longer reach the recursion limit.');

        $this->expectException(RecursionLimitException::class);
        $this->expectExceptionMessage($reference->getMessage());

        Regex::create(['cache' => null])->parse($nested($opener));
    }

    /**
     * An error inside a body is reported as PCRE reports it: the class, the
     * POSIX name or the property it meets, at PCRE's offset, before any
     * error that comes later in the pattern. The oracle is the running
     * engine; the code is one PCRE's message allows.
     */
    #[Test]
    #[DataProvider('provideErrorsInsideBodies')]
    #[DataProvider('provideBodiesReadInPlaceToTheEnd')]
    public function test_validate_reports_an_error_inside_a_body_as_pcre_does(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = self::pcreError($pattern);
        $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
        $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], \sprintf('%s: PCRE says "%s" at %d, the library "%s" at %s.', $pattern, $pcre['message'], $offset, (string) $result->error, var_export($result->offset, true)));
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideErrorsInsideBodies(): iterable
    {
        // PCRE: "missing terminating ] for character class".
        yield 'class left open in a short lookahead' => ['pattern' => '/(?*[a)/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 6];
        yield 'bracket first in a short lookahead' => ['pattern' => '/(?*[])/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 6];
        yield 'escaped bracket in a short lookahead' => ['pattern' => '/(?*[a\\])/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 8];
        yield 'class left open after a closed short lookahead' => ['pattern' => '/(?*a)(?*[a)/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 11];
        yield 'class left open in a short lookahead after a closed body' => ['pattern' => '/(*pla:a)(?*[a)/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 14];
        yield 'class left open in an alphabetic lookahead' => ['pattern' => '/(*pla:[a)/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 9];
        yield 'class left open in a nested group of an atomic body' => ['pattern' => '/(*atomic:([a))/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 14];
        // PCRE: "\c at end of pattern".
        yield 'control escape ending a class in a body' => ['pattern' => '/(*pla:[\\c/', 'code' => ErrorCode::ControlCharInvalid, 'offset' => 9];
        // PCRE: "missing closing parenthesis", at the end of the pattern.
        yield 'short lookahead never closed' => ['pattern' => '/(?*a/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 4];
        yield 'short lookahead never closed around a closed body' => ['pattern' => '/(?*(*pla:a)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        yield 'short lookahead under a newline verb and x' => ['pattern' => "/(*CR)(?*b#c\n))/x", 'code' => ErrorCode::GroupUnclosed, 'offset' => 14];
        // PCRE: "unknown POSIX class name", ahead of the later ")".
        yield 'empty POSIX name before a stray parenthesis' => ['pattern' => '/(*pla:[[::])])/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        yield 'unknown POSIX name before a stray parenthesis' => ['pattern' => '/(*pla:[[:foo:]]))/', 'code' => ErrorCode::PosixInvalid, 'offset' => 14];
        yield 'unknown POSIX name before text and a stray parenthesis' => ['pattern' => '/(*pla:[[:foo:]])a)/', 'code' => ErrorCode::PosixInvalid, 'offset' => 14];
        yield 'unknown POSIX name in a short lookahead' => ['pattern' => '/(?*[[:foo:]])a)/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        yield 'empty POSIX name in a nested body' => ['pattern' => '/(*pla:(*pla:[[::])]))/', 'code' => ErrorCode::PosixInvalid, 'offset' => 17];
        yield 'empty POSIX name in a negative lookahead' => ['pattern' => '/(*nla:[[::])])/', 'code' => ErrorCode::PosixInvalid, 'offset' => 11];
        yield 'empty POSIX name in an atomic body' => ['pattern' => '/(*atomic:[[::])])/', 'code' => ErrorCode::PosixInvalid, 'offset' => 14];
        // PCRE: "malformed \P or \p sequence": the name runs past the ")".
        yield 'property name running past the body' => ['pattern' => '/(*pla:\\p{a)b\\p{L}/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 15];
        // Under "xx" a space or tab before the first member is skipped, so
        // "]" is that member and the class runs to the end.
        yield 'space then bracket under xx' => ['pattern' => '/(?xx)(*pla:[ ])./', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 16];
        yield 'tab then bracket under xx' => ['pattern' => "/(?xx)(*pla:[\t])./", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 16];
        yield 'space then bracket under xx in a short lookahead' => ['pattern' => '/(?xx)(?*[ ])./', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 13];
        yield 'space then bracket under xx in a negative lookahead' => ['pattern' => '/(?xx)(*nla:[ ])./', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 16];
        // Reported where PCRE does already: kept as guards.
        yield 'unknown POSIX name, nothing after the body' => ['pattern' => '/(*pla:[[:foo:]])/', 'code' => ErrorCode::PosixInvalid, 'offset' => 14];
        yield 'empty POSIX name in a lookahead spelled (?=' => ['pattern' => '/(?=[[::])])/', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'property name running past a lookahead spelled (?=' => ['pattern' => '/(?=\\p{a)b\\p{L}/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 12];
        yield 'space then bracket under xx in a lookahead spelled (?=' => ['pattern' => '/(?xx)(?=[ ])./', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 13];
        yield 'stacked quantifier in a body before a stray parenthesis' => ['pattern' => '/(*pla:a{2}{2})b)/', 'code' => ErrorCode::QuantifierNothingToRepeat, 'offset' => 13];
        yield 'alphabetic lookahead never closed' => ['pattern' => '/(*pla:a/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 7];
    }

    /**
     * A body that never closes is read in place to the end of the pattern,
     * as PCRE reads it: nested in a closed body or around one, with a "#"
     * comment of x hiding a ")", a class under xx, a "\Q" running to the
     * end, and a "#" comment ended by the newlines of the convention in
     * force (NEL, U+2028, U+2029 under (*ANY); CR under (*CR)). Where that
     * ending decides what follows, a "[" after it tells the two readings
     * apart: an unclosed class when the comment ends, the unclosed body
     * when it runs on.
     *
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideBodiesReadInPlaceToTheEnd(): iterable
    {
        // PCRE: "missing closing parenthesis", at the end of the pattern.
        yield 'unclosed body after a closed one' => ['pattern' => '/(*pla:a)(?*b/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 12];
        yield 'unclosed short lookahead quoting the rest' => ['pattern' => '/(?*\\Q(?*/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 8];
        // PCRE: "missing terminating ] for character class".
        yield 'class after an x comment ended by a newline' => ['pattern' => "/(*pla:a#c)\n[/x", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 12];
        yield 'class under xx, a space first' => ['pattern' => '/(?xx)(*pla:[ ]/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 14];
        yield 'class under xx, a space before it and in it' => ['pattern' => '/(?xx)(*pla: [ a/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 15];
        yield 'NEL byte ends the comment under (*ANY)' => ['pattern' => "/(*ANY)(?x)(*pla:a#c\x85[b/", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 22];
        yield 'NEL byte ends the comment of a short lookahead under (*ANY)' => ['pattern' => "/(*ANY)(?x)(?*a#c\x85[b/", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 19];
        yield 'NEL ends the comment under (*UTF) and (*ANY)' => ['pattern' => "/(*UTF)(*ANY)(?x)(*pla:a#c\u{85}[b/", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 29];
        yield 'line separator ends the comment under (*UTF) and (*ANY)' => ['pattern' => "/(*UTF)(*ANY)(?x)(*pla:a#c\u{2028}[b/", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 30];
        yield 'paragraph separator ends the comment under (*UTF) and (*ANY)' => ['pattern' => "/(*UTF)(*ANY)(?x)(*pla:a#c\u{2029}[b/", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 30];
        yield 'line separator ends the comment of a short lookahead under the x flag' => ['pattern' => "/(*UTF)(*ANY)(?*a#c\u{2028}[b/x", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 23];
        yield 'carriage return ends the comment under (*CR)' => ['pattern' => "/(*CR)(*pla:a#c\r[b/x", 'code' => ErrorCode::CharclassUnclosed, 'offset' => 17];
        // Reported where PCRE does already: kept as guards.
        // The comment runs on: the "[" is in it, the body is what is unclosed.
        yield 'NEL byte ends no comment under the default newline' => ['pattern' => "/(?x)(*pla:a#c\x85[b/", 'code' => ErrorCode::GroupUnclosed, 'offset' => 16];
        yield 'line separator ends no comment without (*ANY)' => ['pattern' => "/(*UTF)(?x)(*pla:a#c\u{2028}[b/", 'code' => ErrorCode::GroupUnclosed, 'offset' => 24];
        yield 'line feed ends no comment under (*CR)' => ['pattern' => "/(*CR)(*pla:a#c\n[b/x", 'code' => ErrorCode::GroupUnclosed, 'offset' => 17];
        yield 'NEL ends no comment under (*ANYCRLF)' => ['pattern' => "/(*ANYCRLF)(?x)(*pla:a#c\x85[b/", 'code' => ErrorCode::GroupUnclosed, 'offset' => 26];
        // The others: the body is unclosed at the end of the pattern.
        yield 'unclosed body around a closed one' => ['pattern' => '/(*pla:(*pla:a)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 14];
        yield 'unclosed body around a closed one, text after it' => ['pattern' => '/(*pla:(*pla:a)b/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 15];
        yield 'unclosed body around a closed one and an unclosed one' => ['pattern' => '/(*pla:(*pla:a)(*pla:b/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 21];
        yield 'three unclosed bodies' => ['pattern' => '/(*pla:(*pla:(*pla:a/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 19];
        yield 'escaped parenthesis leaving the outer body unclosed' => ['pattern' => '/(*pla:(*pla:a\\))/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 16];
        yield 'unclosed body around a closed short lookahead and body' => ['pattern' => '/(*pla:(?*a)(*pla:b)c/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 20];
        yield 'unclosed body around a closed group' => ['pattern' => '/(*pla:(a)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'unclosed group around a closed body' => ['pattern' => '/((*pla:a)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'x comment hiding the parenthesis' => ['pattern' => '/(*pla:a#)/x', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'x comment hiding the parenthesis, a newline last' => ['pattern' => "/(*pla:a#)\n/x", 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];
        yield 'x comment hiding the parenthesis and a bracket' => ['pattern' => '/(*pla:a#c)[/x', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        yield 'inline x comment hiding the parenthesis' => ['pattern' => '/(?x)(*pla:a#)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 13];
        yield 'class under xx, a space before it' => ['pattern' => '/(?xx)(*pla: [a]/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 15];
        yield 'quote running to the end in a nested body' => ['pattern' => '/(*pla:(*pla:\\Qa)b)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 18];
        yield 'quote hiding a hash under x' => ['pattern' => '/(*pla:\\Q#)/x', 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];
        yield 'quote hiding another opener' => ['pattern' => '/(*pla:a\\Q(*pla:/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 15];
        yield 'NEL ends no comment under (*UTF) alone' => ['pattern' => "/(*UTF)(?x)(*pla:a#c\u{85}b/", 'code' => ErrorCode::GroupUnclosed, 'offset' => 22];
    }

    /**
     * tokenize() reads a body that never closes in place: its opener is a
     * token alone, a verb token whose value is the opener without "(" and
     * its first character ("pla:" or "*"), then the tokens of the body as
     * any text, and the lexer stops at the end of the pattern on the ")"
     * PCRE misses there, at PCRE's offset. A body that closes is one token
     * whole. The tokens read up to the refusal are what the lexer read.
     *
     * @param list<array{TokenType, string, int, int}> $tokens
     */
    #[Test]
    #[DataProvider('provideTokensOfUnclosedBodies')]
    public function test_tokenize_reads_an_unclosed_body_as_its_opener_then_its_text(string $pattern, string $flags, array $tokens): void
    {
        $pcre = self::pcreError('/'.$pattern.'/'.$flags);
        $this->assertSame(['message' => 'missing closing parenthesis', 'offset' => \strlen($pattern)], $pcre, 'Oracle: '.$pattern);

        $lexer = new Lexer();
        $caught = null;

        try {
            $lexer->tokenize($pattern, $flags);
        } catch (LexerException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(LexerException::class, $caught, $pattern.' was read as closed.');
        $this->assertSame([ErrorCode::GroupUnclosed, \strlen($pattern)], [$caught->getErrorCode(), $caught->getPosition()], $caught->getMessage());
        $this->assertSame($tokens, array_map(static fn ($token): array => [$token->type, $token->value, $token->position, $token->end()], $lexer->tokensRead()), $pattern);

        $result = Regex::create(['cache' => null])->validate('/'.$pattern.'/'.$flags);
        $this->assertSame([ErrorCode::GroupUnclosed, \strlen($pattern)], [$result->errorCode, $result->offset], (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, flags: string, tokens: list<array{TokenType, string, int, int}>}>
     */
    public static function provideTokensOfUnclosedBodies(): iterable
    {
        yield 'alphabetic lookahead with text' => ['pattern' => '(*pla:a', 'flags' => '', 'tokens' => [[TokenType::PcreVerb, 'pla:', 0, 6], [TokenType::Literal, 'a', 6, 7]]];
        yield 'alphabetic atomic group with nothing after it' => ['pattern' => '(*atomic:', 'flags' => '', 'tokens' => [[TokenType::PcreVerb, 'atomic:', 0, 9]]];
        yield 'short lookahead with text' => ['pattern' => '(?*a', 'flags' => '', 'tokens' => [[TokenType::PcreVerb, '*', 0, 3], [TokenType::Literal, 'a', 3, 4]]];
        yield 'short lookahead after a closed body' => ['pattern' => '(*pla:a)(?*b', 'flags' => '', 'tokens' => [[TokenType::PcreVerb, 'pla:a', 0, 8], [TokenType::PcreVerb, '*', 8, 11], [TokenType::Literal, 'b', 11, 12]]];
        yield 'body around a closed body' => ['pattern' => '(*pla:(*pla:a)b', 'flags' => '', 'tokens' => [[TokenType::PcreVerb, 'pla:', 0, 6], [TokenType::PcreVerb, 'pla:a', 6, 14], [TokenType::Literal, 'b', 14, 15]]];
        yield 'x comment hiding the parenthesis' => ['pattern' => '(*pla:a#)', 'flags' => 'x', 'tokens' => [[TokenType::PcreVerb, 'pla:', 0, 6], [TokenType::Literal, 'a', 6, 7], [TokenType::Literal, '#', 7, 8], [TokenType::Literal, ')', 8, 9]]];
    }

    /**
     * The body is read in the state around it, as the body of "(?=" is:
     * under "xx" the spaces and tabs of a class are no members, "(?-x)"
     * inside the body clears it, "(?-x:" around the body too. Each body
     * gives the tree of its "(?=" spelling, and the engine agrees on every
     * subject between the two spellings.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideBodiesUnderXx')]
    public function test_parse_reads_the_body_under_the_options_around_it(string $pattern, string $lookahead, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($lookahead, $subject), preg_match($pattern, $subject), \sprintf('Oracle: %s and %s on %s.', $pattern, $lookahead, json_encode($subject)));
        }

        $regex = Regex::create(['cache' => null]);
        $this->assertTrue($regex->validate($pattern)->isValid, \sprintf('%s compiles: %s', $pattern, (string) $regex->validate($pattern)->error));

        $tree = static fn (string $source): string => $regex->parse($source)->accept(new AsciiTreeRenderer());
        $this->assertStringContainsString('CharClass', $tree($lookahead), 'The tree shows the class.');
        $this->assertSame($tree($lookahead), $tree($pattern), $pattern);

        $compiled = $regex->parse($pattern)->accept(new PatternPrinter());
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s, which disagrees on %s.', $pattern, $compiled, json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, lookahead: string, subjects: list<string>}>
     */
    public static function provideBodiesUnderXx(): iterable
    {
        // PCRE: " x" → 0, "ax" → 1.
        yield 'space before a member' => ['pattern' => '/(?xx)(*pla:[ a])./', 'lookahead' => '/(?xx)(?=[ a])./', 'subjects' => [' x', 'ax', 'x']];
        yield 'tab before a member' => ['pattern' => "/(?xx)(*pla:[\ta])./", 'lookahead' => "/(?xx)(?=[\ta])./", 'subjects' => ["\tx", 'ax']];
        yield 'space before a bracket read as a member' => ['pattern' => '/(?xx)(*pla:[ ]a)])./', 'lookahead' => '/(?xx)(?=[ ]a)])./', 'subjects' => [']x', ')x', 'ax', ' x']];
        yield 'space before a member in a negative lookahead' => ['pattern' => '/(?xx)(*nla:[ a])./', 'lookahead' => '/(?xx)(?![ a])./', 'subjects' => [' x', 'ax']];
        yield 'space before a member under (*UTF)' => ['pattern' => '/(*UTF)(?xx)(*pla:[ é])./', 'lookahead' => '/(*UTF)(?xx)(?=[ é])./', 'subjects' => [' x', 'éx']];
        // Read as the engine reads them already: kept as guards.
        yield 'a single x keeps the space a member' => ['pattern' => '/(?x)(*pla:[ ])./', 'lookahead' => '/(?x)(?=[ ])./', 'subjects' => [' x', 'ax']];
        yield 'x cleared inside the body' => ['pattern' => '/(?xx)(*pla:(?-x)[ ]a)./', 'lookahead' => '/(?xx)(?=(?-x)[ ]a)./', 'subjects' => [' ax', 'ax']];
        yield 'x cleared around the body' => ['pattern' => '/(?xx)(?-x:(*pla:[ ]a))./', 'lookahead' => '/(?xx)(?-x:(?=[ ]a))./', 'subjects' => [' ax', 'ax']];
        yield 'xx set inside the body' => ['pattern' => '/(*pla:(?xx)[ a])./', 'lookahead' => '/(?=(?xx)[ a])./', 'subjects' => [' x', 'ax']];
    }

    /**
     * A script run written in a body is a script run: the tree is the one
     * of the "(?=" spelling, and the printed pattern agrees with the engine,
     * which refuses a run mixing two scripts where "(?:" would take it.
     *
     * @param list<array{string, int}> $subjects each subject and whether the engine matches it
     */
    #[Test]
    #[DataProvider('provideScriptRunsInBodies')]
    public function test_parse_reads_a_script_run_in_a_body_as_its_lookahead_spelling(string $pattern, string $lookahead, array $subjects): void
    {
        $regex = Regex::create(['cache' => null]);
        $tree = static fn (string $source): string => $regex->parse($source)->accept(new AsciiTreeRenderer());
        $this->assertSame($tree($lookahead), $tree($pattern), $pattern);

        $compiled = $regex->parse($pattern)->accept(new PatternPrinter());
        foreach ($subjects as [$subject, $matches]) {
            $this->assertSame($matches, preg_match($pattern, $subject), \sprintf('Oracle: %s on %s.', $pattern, json_encode($subject)));
            $this->assertSame($matches, preg_match($compiled, $subject), \sprintf('%s compiled to %s, which disagrees on %s.', $pattern, $compiled, json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, lookahead: string, subjects: list<array{string, int}>}>
     */
    public static function provideScriptRunsInBodies(): iterable
    {
        yield 'script run in a lookahead' => ['pattern' => '/^(*pla:(*sr:\w+$))/u', 'lookahead' => '/^(?=(*sr:\w+$))/u', 'subjects' => [['ab', 1], ["a\u{3b1}", 0]]];
        yield 'script run in a negative lookahead' => ['pattern' => '/^(*nla:(*sr:\w+$))/u', 'lookahead' => '/^(?!(*sr:\w+$))/u', 'subjects' => [['ab', 0], ["a\u{3b1}", 1]]];
        yield 'atomic script run in a lookahead' => ['pattern' => '/^(*pla:(*asr:\w+$))/u', 'lookahead' => '/^(?=(*asr:\w+$))/u', 'subjects' => [['ab', 1], ["a\u{3b1}", 0]]];
    }

    /**
     * The Python spelling of the body is the one of its "(?=" spelling: no
     * space in the class that PCRE skips (" x" matches "(?=[ a])." but not
     * the PCRE pattern).
     */
    #[Test]
    public function test_transpile_reads_the_body_under_xx_as_its_lookahead_spelling(): void
    {
        $this->assertSame(0, preg_match('/(?xx)(*pla:[ a])./', ' x'), 'Oracle: the space is no member.');

        $regex = Regex::create(['cache' => null]);

        $this->assertSame(
            $regex->transpile('/(?xx)(?=[ a])./', 'python')->literal,
            $regex->transpile('/(?xx)(*pla:[ a])./', 'python')->literal,
        );
    }

    /**
     * tokenize() finds the end of the body under "xx": in "[ ]a)]" the "]"
     * after the space is a member, so the body runs to the ")" after the
     * class (PCRE compiles the pattern). A class that "xx" leaves unclosed
     * is refused while tokenizing, where PCRE refuses it.
     */
    #[Test]
    public function test_tokenize_reads_the_body_under_xx(): void
    {
        $this->assertSame(1, preg_match('/(?xx)(*pla:[ ]a)])./', ')x'), 'Oracle: the class holds ")".');

        $tokens = Regex::tokenize('/(?xx)(*pla:[ ]a)])./')->getTokens();

        $this->assertSame(
            [[TokenType::PcreVerb, 'pla:[ ]a)]', 5], [TokenType::Dot, '.', 18], [TokenType::Eof, '', 19]],
            array_map(static fn ($token): array => [$token->type, $token->value, $token->position], \array_slice($tokens, 4)),
        );
    }

    #[Test]
    public function test_tokenize_refuses_a_class_xx_leaves_open_in_a_body(): void
    {
        $this->assertSame(['message' => 'missing terminating ] for character class', 'offset' => 16], self::pcreError('/(?xx)(*pla:[ ])./'));

        $caught = null;

        try {
            Regex::tokenize('/(?xx)(*pla:[ ])./');
        } catch (LexerException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(LexerException::class, $caught, 'The class is read as closed.');
        $this->assertSame([ErrorCode::CharclassUnclosed, 16], [$caught->getErrorCode(), $caught->getPosition()]);
    }

    /**
     * Looking for an earlier error inside each body does not read the
     * bodies again for each one: an error after many closed bodies is
     * found in linear time.
     */
    #[Test]
    #[DataProvider('provideClosedBodiesBeforeAnError')]
    public function test_validate_finds_an_error_after_many_bodies_in_linear_time(string $unit): void
    {
        $this->assertFalse(@preg_match('/'.$unit.')/', ''), 'Oracle: the stray parenthesis is refused.');

        $this->assertLinearTime(
            static function (int $size) use ($unit): void {
                Regex::create(['cache' => null])->validate('/'.str_repeat($unit, $size).')/');
            },
            2_000,
            $unit,
        );
    }

    /**
     * @return iterable<string, array{unit: string}>
     */
    public static function provideClosedBodiesBeforeAnError(): iterable
    {
        yield 'text bodies' => ['unit' => '(*pla:a)'];
        yield 'class bodies' => ['unit' => '(*pla:[a])'];
        yield 'short lookaheads holding a POSIX class' => ['unit' => '(?*[[:alpha:]])'];
    }

    /**
     * A name PCRE does not know opens no body: PCRE refuses it at its colon
     * before reading what follows ("(*alpha_assertion) not recognized"),
     * whether the body would close or not, and whatever it holds, a class
     * or a quote left open included. The lexer reads no body for it and
     * refuses the name where PCRE does.
     *
     * The library agrees with the running engine on every release, the
     * offset and a code its message allows; the row holds what PCRE2 10.49
     * reports, checked against the engine when it is 10.49.
     */
    #[Test]
    #[DataProvider('provideUnknownNamesWhoseBodyNeverCloses')]
    public function test_validate_refuses_an_unknown_name_before_its_body_as_pcre_does(string $pattern, int $offset): void
    {
        $pcre = self::pcreError($pattern);
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        if ('10.49' === self::runningRelease()) {
            $this->assertSame(['message' => '(*alpha_assertion) not recognized', 'offset' => $offset], $pcre, 'Oracle: '.$pattern);
        }

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($pcre['offset'], $result->offset, \sprintf('%s: PCRE says "%s" at %d, the library "%s".', $pattern, $pcre['message'], (int) $pcre['offset'], (string) $result->error));
        $this->assertContains($result->errorCode?->value, PcreMessageCodes::CODES[$pcre['message']], \sprintf('%s: PCRE says "%s", the library "%s".', $pattern, $pcre['message'], (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnknownNamesWhoseBodyNeverCloses(): iterable
    {
        yield 'text after the colon' => ['pattern' => '/(*foo:a/', 'offset' => 5];
        yield 'nothing after the colon' => ['pattern' => '/(*foo:/', 'offset' => 5];
        yield 'name with an underscore' => ['pattern' => '/(*foo_bar:a/', 'offset' => 9];
        yield 'after text' => ['pattern' => '/a(*foo:(b)/', 'offset' => 6];
        yield 'a closed body after it' => ['pattern' => '/(*foo:a(*pla:b)/', 'offset' => 5];
        yield 'class left open after it' => ['pattern' => '/(*foo:[)/', 'offset' => 5];
        yield 'quote left open after it' => ['pattern' => '/(*foo:\\Q)/', 'offset' => 5];
        yield 'escaped backslash after it' => ['pattern' => '/(*foo:a\\\\/', 'offset' => 5];
        yield 'under x, a comment hiding the parenthesis' => ['pattern' => '/(?x)(*foo: a # )/', 'offset' => 9];
        yield 'under u' => ['pattern' => '/(*foo:a/u', 'offset' => 5];
        yield 'in an alphabetic lookahead never closed' => ['pattern' => '/(*pla:(*foo:a/', 'offset' => 11];
        yield 'in a short lookahead never closed' => ['pattern' => '/(?*(*foo:a/', 'offset' => 8];
        yield 'after a closed body' => ['pattern' => '/(*pla:a)(*foo:b/', 'offset' => 13];
        yield 'as the condition' => ['pattern' => '/(?(*foo:a/', 'offset' => 7];
        yield 'as the condition, after a callout' => ['pattern' => '/(?(?C1)(*foo:a/', 'offset' => 12];
    }

    /**
     * The refusal names the opener PCRE does not know, as written.
     */
    #[Test]
    #[DataProvider('provideUnknownOpeners')]
    public function test_validate_names_the_unknown_assertion_it_refuses(string $pattern, string $opener): void
    {
        $pcre = self::pcreError($pattern);
        $this->assertContains(ErrorCode::VerbInvalid->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame(ErrorCode::VerbInvalid, $result->errorCode, $pattern);
        $this->assertStringContainsString('"'.$opener.'"', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, opener: string}>
     */
    public static function provideUnknownOpeners(): iterable
    {
        yield 'a name of letters' => ['pattern' => '/(*foo:a/', 'opener' => '(*foo:'];
        yield 'a name with an underscore' => ['pattern' => '/(*foo_bar:a/', 'opener' => '(*foo_bar:'];
        yield 'a known name with a letter more' => ['pattern' => '/(*plaa:/', 'opener' => '(*plaa:'];
        yield 'in an alphabetic lookahead never closed' => ['pattern' => '/(*pla:(*foo:a/', 'opener' => '(*foo:'];
    }

    /**
     * The lexer itself reads no body PCRE does not read, when the body never
     * closes: an unknown name, or a name PCRE knows standing where the
     * condition of "(?(" belongs and naming no lookaround there, after a
     * callout or not. It refuses the name where PCRE does, so no token of
     * the body and no ")" missing at the end is what stops it. The tokens
     * read up to the refusal are what the lexer read.
     *
     * @param list<array{TokenType, string, int, int}> $tokens
     */
    #[Test]
    #[DataProvider('provideBodiesTheLexerDoesNotRead')]
    public function test_tokenize_refuses_a_body_pcre_does_not_read_where_pcre_does(string $pattern, ErrorCode $code, int $offset, array $tokens): void
    {
        $pcre = self::pcreError('/'.$pattern.'/');
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        if ('10.49' === self::runningRelease()) {
            $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
            $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $lexer = new Lexer();
        $caught = null;

        try {
            $lexer->tokenize($pattern);
        } catch (LexerException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(LexerException::class, $caught, $pattern.' was read whole.');
        $this->assertSame([$code, $offset], [$caught->getErrorCode(), $caught->getPosition()], $caught->getMessage());
        $this->assertSame($tokens, array_map(static fn ($token): array => [$token->type, $token->value, $token->position, $token->end()], $lexer->tokensRead()), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int, tokens: list<array{TokenType, string, int, int}>}>
     */
    public static function provideBodiesTheLexerDoesNotRead(): iterable
    {
        // PCRE: "(*alpha_assertion) not recognized", at the colon.
        yield 'unknown name after text' => ['pattern' => 'a(*foo:b', 'code' => ErrorCode::VerbInvalid, 'offset' => 6, 'tokens' => [[TokenType::Literal, 'a', 0, 1]]];
        yield 'unknown name with nothing after the colon' => ['pattern' => '(*foo:', 'code' => ErrorCode::VerbInvalid, 'offset' => 5, 'tokens' => []];
        // PCRE: "atomic assertion expected after (?( or (?(?C)", at the colon.
        yield 'atomic group as the condition' => ['pattern' => '(?(*atomic:a', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 10, 'tokens' => [[TokenType::GroupModifierOpen, '(?', 0, 2]]];
        yield 'short script run as the condition' => ['pattern' => '(?(*sr:a', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 6, 'tokens' => [[TokenType::GroupModifierOpen, '(?', 0, 2]]];
        yield 'non-atomic lookahead as the condition' => ['pattern' => '(?(*napla:a', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 9, 'tokens' => [[TokenType::GroupModifierOpen, '(?', 0, 2]]];
        yield 'substring scan as the condition' => ['pattern' => '(?(*scs:(1)a', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7, 'tokens' => [[TokenType::GroupModifierOpen, '(?', 0, 2]]];
        yield 'atomic group as the condition after a callout' => ['pattern' => '(?(?C1)(*atomic:a', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 15, 'tokens' => [[TokenType::GroupModifierOpen, '(?', 0, 2], [TokenType::Callout, '1', 2, 7]]];
    }

    /**
     * A "\p{" or "\P{" that no "}" closes swallows the rest of the pattern
     * for PCRE: an alphabetic assertion, a stray ")", a "(**", a class or a
     * repeated name after it is never read, and PCRE reports the property
     * ("malformed \P or \p sequence") at the end of the pattern.
     *
     * The library agrees with the running engine on every release, the
     * offset and a code its message allows; the row holds what PCRE2 10.49
     * reports, checked against the engine when it is 10.49.
     */
    #[Test]
    #[DataProvider('provideUnclosedPropertiesSwallowingTheRest')]
    public function test_validate_reports_an_unclosed_property_before_what_it_swallows(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideUnclosedPropertiesSwallowingTheRest(): iterable
    {
        // PCRE: "malformed \P or \p sequence", at the end of the pattern.
        yield 'a body holding a stacked quantifier' => ['pattern' => '/\\p{(*pla:++/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 11];
        yield 'a stray parenthesis and a double star' => ['pattern' => '/\\p{)(**/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 7];
        yield 'a body holding a reversed range' => ['pattern' => '/\\p{(*pla:[z-a]/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 14];
        yield 'a body holding a repeated name' => ['pattern' => '/\\p{(*pla:(?<n>a)(?<n>b)/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 23];
        yield 'an unclosed verb and a double star' => ['pattern' => '/\\p{(*(**/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 8];
        yield 'negated, a body holding a stacked quantifier' => ['pattern' => '/\\P{(*pla:++/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 11];
        yield 'negated, a stray parenthesis and a double star' => ['pattern' => '/\\P{)(**/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 7];
        yield 'negated, a body holding a repeated name' => ['pattern' => '/\\P{(*pla:(?<n>a)(?<n>b)/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 23];
        yield 'in a group, a body holding a reversed range' => ['pattern' => '/(\\p{(*pla:[z-a]/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 15];
        yield 'in a body, a body holding a stacked quantifier' => ['pattern' => '/(*pla:\\p{(*pla:++/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 17];
        yield 'negated in a body, an unclosed verb and a double star' => ['pattern' => '/(*pla:\\P{(*(**/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 14];
        // A "}" before the property closes nothing: it still swallows the rest.
        yield 'negated after a brace, a double star' => ['pattern' => '/}\\P{(**/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 7];
        // An escaped backslash then "P{" is text: nothing is swallowed.
        // PCRE: "escape sequence is invalid in character class".
        yield 'an escaped backslash, then P, a brace and a class holding \\K' => ['pattern' => '/\\\\P{[\\Q\\E\\K(/', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 11];
        // Reported where PCRE reports them already: kept as guards.
        yield 'in a group, a stray parenthesis and a double star' => ['pattern' => '/(\\p{)(**/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 8];
        yield 'in a body, a stray parenthesis and a double star' => ['pattern' => '/(*pla:\\p{)(**/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 13];
    }

    /**
     * A body that never closes, ending in a construct cut short, is
     * "missing closing parenthesis" at the end of the pattern for PCRE: an
     * opener "(?(", "(?P" or "(?(?C1)" with nothing after it, or a verb name
     * left open. A callout cut short before its ")" is PCRE's "closing
     * parenthesis for (?C expected" instead.
     *
     * The library agrees with the running engine on every release, the
     * offset and a code its message allows; the row holds what PCRE2 10.49
     * reports, checked against the engine when it is 10.49.
     */
    #[Test]
    #[DataProvider('provideUnclosedBodiesEndingInAConstructCutShort')]
    public function test_validate_refuses_an_unclosed_body_ending_in_a_construct_cut_short(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideUnclosedBodiesEndingInAConstructCutShort(): iterable
    {
        // PCRE: "missing closing parenthesis", at the end of the pattern.
        yield 'a condition opener' => ['pattern' => '/(*pla:(?(/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'a (?P opener' => ['pattern' => '/(*pla:(?P/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'a callout condition with nothing after it' => ['pattern' => '/(*pla:(?(?C1)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 13];
        yield 'a callout condition then an assertion name left open' => ['pattern' => '/(*pla:(?(?C1)(*pl/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 17];
        // A verb name left open is refused as one, as "/(*p/" is.
        yield 'a verb name left open' => ['pattern' => '/(*pla:(*p/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 9];
        // PCRE: "closing parenthesis for (?C expected".
        yield 'a callout condition cut short' => ['pattern' => '/(*pla:(?(?C1/', 'code' => ErrorCode::CalloutUnclosed, 'offset' => 12];
        // PCRE: "atomic assertion expected after (?( or (?(?C)", where the
        // condition starts: a lookahead opener the pattern ends on.
        yield 'a lookahead opener as the condition' => ['pattern' => '/(*pla:(?(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 9];
        yield 'a lookahead opener after a callout condition' => ['pattern' => '/(*pla:(?(?C1)(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 13];
        yield 'a negative lookahead opener after a comment' => ['pattern' => '/(*pla:(?(?#c)(?!/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 14];
        // Reported where PCRE reports them already: kept as guards.
        yield 'an assertion name left open' => ['pattern' => '/(*pla:(*pla/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 11];
        yield 'an assertion name left open as the condition' => ['pattern' => '/(*pla:(?(*pl/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 12];
    }

    /**
     * The same constructs cut short at the end of the pattern, with no body
     * around them: PCRE refuses them as it does inside a body.
     */
    #[Test]
    #[DataProvider('provideConstructsCutShortOutsideABody')]
    public function test_validate_refuses_a_construct_cut_short_outside_a_body(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideConstructsCutShortOutsideABody(): iterable
    {
        // PCRE: "missing closing parenthesis", at the end of the pattern.
        yield 'a condition opener' => ['pattern' => '/(?(/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 3];
        yield 'a (?P opener' => ['pattern' => '/(?P/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 3];
        yield 'a callout condition with nothing after it' => ['pattern' => '/(?(?C1)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 7];
        yield 'a callout condition then an assertion name left open' => ['pattern' => '/(?(?C1)(*pl/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        // PCRE: "closing parenthesis for (?C expected".
        yield 'a callout condition cut short' => ['pattern' => '/(?(?C1/', 'code' => ErrorCode::CalloutUnclosed, 'offset' => 6];
        // PCRE: "atomic assertion expected after (?( or (?(?C)": a lookahead
        // opener the pattern ends on is no assertion yet. Reported where
        // the condition starts, after any callout or comment before it.
        yield 'a lookahead opener as the condition' => ['pattern' => '/(?(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'a negative lookahead opener as the condition' => ['pattern' => '/(?(?!/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        yield 'a lookahead opener after a callout condition' => ['pattern' => '/(?(?C1)(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        yield 'a negative lookahead opener after a callout condition' => ['pattern' => '/(?(?C1)(?!/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 7];
        yield 'a lookahead opener after a string callout condition' => ['pattern' => '/(?(?C"x")(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 9];
        yield 'a lookahead opener after a comment' => ['pattern' => '/(?(?#c)(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 8];
        yield 'a lookahead opener after a callout then a comment' => ['pattern' => '/(?(?C1)(?#c)(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 12];
        yield 'a lookahead opener after a comment then a callout' => ['pattern' => '/(?(?#c)(?C1)(?=/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 12];
        // PCRE: "missing closing parenthesis", at the end of the pattern: a
        // lookbehind opener, or a lookahead holding anything, is read as
        // the assertion. Reported there already: kept as guards.
        yield 'a lookbehind opener as the condition' => ['pattern' => '/(?(?<=/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 6];
        yield 'a negative lookbehind opener as the condition' => ['pattern' => '/(?(?<!/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 6];
        yield 'a lookbehind opener after a callout condition' => ['pattern' => '/(?(?C1)(?<=/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        yield 'a negative lookbehind opener after a comment' => ['pattern' => '/(?(?#c)(?<!/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        yield 'a lookahead holding a letter as the condition' => ['pattern' => '/(?(?=a/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 6];
        yield 'an empty lookahead as the condition' => ['pattern' => '/(?(?=)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 6];
        // What PCRE says here depends on the release: compared with the
        // running engine, PCRE2 10.49's verdict pinned. 10.49: "atomic
        // assertion expected after (?( or (?(?C)" at 3.
        yield 'a one-letter name left open as the condition' => ['pattern' => '/(?(*p/', 'code' => ErrorCode::ConditionAssertionExpected, 'offset' => 3];
        // 10.49: "missing closing parenthesis" at 6.
        yield 'an assertion name left open as the condition' => ['pattern' => '/(?(*pl/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 6];
        // Reported where PCRE reports it already: kept as a guard.
        yield 'a verb name left open' => ['pattern' => '/(*p/', 'code' => ErrorCode::VerbUnclosed, 'offset' => 3];
    }

    /**
     * The library refuses the pattern at the running engine's offset, with a
     * code the engine's message allows; on PCRE2 10.49 the row's offset and
     * code are the engine's and the library's.
     */
    private function assertRefusedAsTheEngineRefuses(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = self::pcreError($pattern);
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        $allowed = PcreMessageCodes::CODES[$pcre['message']];
        $pinned = '10.49' === self::runningRelease();
        if ($pinned) {
            $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
            $this->assertContains($code->value, $allowed, \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $result = Regex::create(['cache' => null])->validate($pattern);
        $said = \sprintf('%s: PCRE says "%s" at %s, the library "%s" (%s) at %s.', $pattern, $pcre['message'], var_export($pcre['offset'], true), (string) $result->error, $result->errorCode?->value, var_export($result->offset, true));

        $this->assertFalse($result->isValid, $said);
        $this->assertSame($pcre['offset'], $result->offset, $said);
        $this->assertContains($result->errorCode?->value, $allowed, $said);
        if ($pinned) {
            $this->assertSame($code, $result->errorCode, $said);
        }
    }

    /**
     * PCRE's message and offset for a pattern it refuses.
     *
     * @return array{message: string, offset: int|null}
     */
    private static function pcreError(string $pattern): array
    {
        return PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
    }

    /**
     * Reads $size units then twice as many, and asserts each read takes
     * less than the budget and the larger one less than three times the
     * smaller. Each size is timed at its best of three runs while it stays
     * short, so a pause of the machine does not count.
     *
     * @param \Closure(int): void $read
     */
    private function assertLinearTime(\Closure $read, int $size, string $what): void
    {
        $budget = 1.0;

        $small = self::bestTime($read, $size);
        $this->assertLessThan($budget, $small, \sprintf('%s x %d: %.3f s.', $what, $size, $small));

        $large = self::bestTime($read, 2 * $size);
        $this->assertLessThan($budget, $large, \sprintf('%s x %d: %.3f s, %.3f s for half as many.', $what, 2 * $size, $large, $small));

        // Below a few milliseconds the clock says more than the reading.
        if ($large >= 0.02) {
            $this->assertLessThan(3.0, $large / $small, \sprintf('%s: %.3f s for %d units, %.3f s for %d.', $what, $small, $size, $large, 2 * $size));
        }
    }

    /**
     * The best of three readings: a busy machine or a coverage driver slows
     * one reading down, rarely all three.
     *
     * @param \Closure(int): void $read
     */
    private static function bestTime(\Closure $read, int $size): float
    {
        $best = \INF;
        for ($run = 0; $run < 3; $run++) {
            $start = hrtime(true);
            $read($size);
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
