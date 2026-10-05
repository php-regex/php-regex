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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Token\TokenType;
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
     * character class", where the library names the alphabetic assertion
     * around it: the offset is the one PCRE reports.
     */
    #[Test]
    #[DataProvider('provideUnclosedClassesInBodies')]
    public function test_validate_refuses_a_class_in_the_body_that_never_closes(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($offset, $result->offset, $pattern);
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
     * 10.49's, the pattern given in hex: "\ at end of pattern".
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
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideBodiesEndingInABackslash(): iterable
    {
        yield 'after text' => ['pattern' => '(*pla:a\\', 'offset' => 8];
        yield 'inside a class' => ['pattern' => '(*pla:[a\\', 'offset' => 9];
        yield 'inside a nested group' => ['pattern' => '(*pla:a(b\\', 'offset' => 10];
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
}
