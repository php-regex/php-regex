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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under /m, "^" holds at the start of the subject, or after a newline more
 * follows: never after a character that ends no line under the convention,
 * nor at the end of the subject once something was read. "$" and "\Z" stand
 * before the newline that ends the subject, "\r\n" under (*CRLF), after
 * which a "^" never holds.
 */
final class MultilineAnchorImpossibleTest extends TestCase
{
    private const LETTERS = ['a', 'b', "\r", "\n", "\0"];

    /**
     * The engine finds no match, with the JIT or without, in any string up
     * to five characters over "a", "b", "\r", "\n" and NUL, from any offset.
     */
    #[Test]
    #[DataProvider('provideAnchorsNothingReaches')]
    public function test_an_anchor_no_subject_reaches_is_reported(string $pattern, string $id): void
    {
        foreach (self::subjects() as $subject) {
            for ($offset = 0; $offset <= \strlen($subject); $offset++) {
                $this->assertSame(0, preg_match($pattern, $subject, $matches, 0, $offset), \sprintf('%s on %s from %d.', $pattern, json_encode($subject), $offset));
                $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject, $matches, 0, $offset), \sprintf('%s on %s from %d, without the JIT.', $pattern, json_encode($subject), $offset));
            }
        }

        $this->assertContains($id, $this->issueIds($pattern), $pattern);
    }

    /**
     * The engine finds a match, with the JIT and without: the rule says
     * nothing of the anchors.
     */
    #[Test]
    #[DataProvider('provideNearMisses')]
    public function test_an_anchor_a_subject_reaches_is_not_reported(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        $this->assertSame(1, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), $pattern.', without the JIT');

        $ids = $this->issueIds($pattern);
        $this->assertNotContains('regex.lint.anchor.impossible.start', $ids, $pattern);
        $this->assertNotContains('regex.lint.anchor.impossible.end', $ids, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, id: string}>
     */
    public static function provideAnchorsNothingReaches(): iterable
    {
        $start = 'regex.lint.anchor.impossible.start';
        $end = 'regex.lint.anchor.impossible.end';

        // A character that ends no line stands before the "^".
        yield 'a line end then a line start' => ['pattern' => '/a$^/m', 'id' => $start];
        yield 'a line start after a letter' => ['pattern' => '/a^/m', 'id' => $start];
        yield 'a line start after a letter, an option between' => ['pattern' => '/a(?i)^/m', 'id' => $start];
        yield 'a line start after a carriage return under (*CRLF)' => ['pattern' => '/(*CRLF)a$\r^\n/m', 'id' => $start];
        yield 'a line start after a line feed under (*CR)' => ['pattern' => '/(*CR)a$\n^/m', 'id' => $start];
        yield 'a line start after a carriage return under (*NUL)' => ['pattern' => '/(*NUL)\r^b/m', 'id' => $start];
        yield 'a line start after a class of letters' => ['pattern' => '/[ab]^/m', 'id' => $start];
        // The "^" stands at the end of the subject, after a character.
        yield 'a line start after the end' => ['pattern' => '/a\z^/m', 'id' => $start];
        yield 'a line start before the end' => ['pattern' => '/\n^\z/m', 'id' => $start];
        yield 'a line start before a lookahead and the end' => ['pattern' => '/\n^(?=)\z/m', 'id' => $start];
        yield 'a line start in a lookahead, the end after as many characters' => ['pattern' => '/a(?=\n^)\n\z/m', 'id' => $start];
        yield 'a line start in a lookahead two characters on' => ['pattern' => '/a(?=b\n^b)b\n\z/m', 'id' => $start];
        // "\Z" and "$" stand before the newline that ends the subject.
        yield 'a line start after the final carriage return and line feed' => ['pattern' => '/(*CRLF)a\Z\r\n^/m', 'id' => $end];
        yield 'a line start after the final newline under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)a\Z\r\n^/m', 'id' => $end];
        yield 'a line start after a final line feed under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)a\Z\n^/m', 'id' => $end];
        yield 'a letter after the final carriage return under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)a\Z\rb/', 'id' => $end];
        yield 'a dollar before a carriage return and a letter under (*CRLF)' => ['pattern' => '/(*CRLF)a$\r\nb/', 'id' => $end];
        // What the tail reads of the newline, item by item.
        yield 'a line start after a line break under (*CRLF)' => ['pattern' => '/(*CRLF)a\Z\R^/m', 'id' => $end];
        yield 'a letter after either order of the newline' => ['pattern' => '/(*CRLF)a\Z(?:\r\n|\n\r)b/', 'id' => $end];
        yield 'a letter after two newline characters' => ['pattern' => '/(*CRLF)a\Z[\r\n]{2}b/', 'id' => $end];
        yield 'a letter after a conditional' => ['pattern' => '/(*CRLF)a\Z(?(?=\r)\r\n|\n)b/', 'id' => $end];
        yield 'a word boundary after the newline' => ['pattern' => '/(*ANYCRLF)a\Z\r\n\b/', 'id' => $end];
        yield 'a subject start after the newline' => ['pattern' => '/(*CRLF)a\Z\r\n\A/', 'id' => $end];
        yield 'a match start after a letter' => ['pattern' => '/(*CRLF)a\Z\G\r\n/', 'id' => $end];
        yield 'a dollar inside the newline under (*CRLF)' => ['pattern' => '/(*CRLF)a\Z\r$\n/', 'id' => $end];
        yield 'a capital Z inside the newline under (*CRLF)' => ['pattern' => '/(*CRLF)a\Z\r\Z\n/', 'id' => $end];
        yield 'a line start inside the newline under (*CRLF)' => ['pattern' => '/(*CRLF)a\Z\r^\n/m', 'id' => $end];
        yield 'a failing verb after the newline' => ['pattern' => '/(*CRLF)a\Z\r\n(*FAIL)/', 'id' => $end];
        yield 'a group that always fails after the newline' => ['pattern' => '/(*CRLF)a\Z\r\n(?!)/', 'id' => $end];
        // A set the analyzer does not read is put to the automata.
        yield 'a letter property before the line feed' => ['pattern' => '/(*CRLF)a\Z\pL\n/', 'id' => $end];
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideNearMisses(): iterable
    {
        yield 'a line start after a line feed' => ['pattern' => '/a$\n^/m', 'subject' => "a\nb"];
        yield 'a line start after a carriage return and line feed' => ['pattern' => '/(*CRLF)a$\r\n^/m', 'subject' => "a\r\nb"];
        yield 'a line start between cr and lf' => ['pattern' => '/(*ANYCRLF)a$\r^\n/m', 'subject' => "a\r\n"];
        yield 'a line start after a carriage return under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)\r^a/m', 'subject' => "\ra"];
        yield 'a line start after a carriage return under (*CR)' => ['pattern' => '/(*CR)\r^a/m', 'subject' => "\ra"];
        yield 'a line start after NUL' => ['pattern' => '/(*NUL)\x00^a/m', 'subject' => "\0a"];
        yield 'a line start after a vertical tab under (*ANY)' => ['pattern' => '/(*ANY)\x0B^a/m', 'subject' => "\x0Ba"];
        yield 'a line start after NEL under (*ANY)' => ['pattern' => '/(*ANY)\x85^a/m', 'subject' => "\x85a"];
        yield 'a line start after a separator under (*ANY) and u' => ['pattern' => '/(*ANY)\x{2028}^a/mu', 'subject' => "\u{2028}a"];
        yield 'a line start after an optional line feed' => ['pattern' => '/a\n?^/m', 'subject' => "a\nb"];
        yield 'a line start after a letter or a line feed' => ['pattern' => '/(?:a|\n)^/m', 'subject' => "\nb"];
        yield 'a line start after anything but a letter' => ['pattern' => '/[^a]^/m', 'subject' => "\nb"];
        yield 'a line start after any character' => ['pattern' => '/.^/ms', 'subject' => "\nb"];
        yield 'a line start after a space' => ['pattern' => '/\s^/m', 'subject' => "\nb"];
        yield 'a line start before the final newline' => ['pattern' => '/\n^\Z/m', 'subject' => "\n\n"];
        yield 'a line start after a final-newline anchor' => ['pattern' => '/\n\Z^/m', 'subject' => "\n\n"];
        yield 'a line start at the start, before the end' => ['pattern' => '/^\z/m', 'subject' => ''];
        yield 'a line start before an accept and the end' => ['pattern' => '/\n^(*ACCEPT)\z/m', 'subject' => "\nb"];
        yield 'a line start in a lookahead, more read after' => ['pattern' => '/a(?=\n^)\n/m', 'subject' => "a\nb"];
        yield 'a line start in a lookahead, before a final newline' => ['pattern' => '/a(?=\n^)\n\Z/m', 'subject' => "a\n\n"];
        yield 'a line start in a negative lookahead' => ['pattern' => '/a(?!\n^)\n\z/m', 'subject' => "a\n"];
        yield 'a line start between cr and lf after the final newline anchor' => ['pattern' => '/(*ANYCRLF)a\Z\r^/m', 'subject' => "a\r\n"];
        yield 'the final carriage return and line feed' => ['pattern' => '/(*CRLF)a\Z\r\n/m', 'subject' => "a\r\n"];
        yield 'the final line feed under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)a\r\Z\n/', 'subject' => "a\r\n"];
        yield 'a line end before a carriage return and a line feed' => ['pattern' => '/(*CRLF)a$\r\nb/m', 'subject' => "a\r\nb"];
        // What the tail reads of the newline, item by item.
        yield 'the end after either newline character' => ['pattern' => '/(*ANYCRLF)a\Z(?:\r|\n)\z/', 'subject' => "a\r"];
        yield 'a reference after the newline' => ['pattern' => '/(*CRLF)a\Z(\r)\n\1?/', 'subject' => "a\r\n"];
        yield 'an empty lookahead after the newline' => ['pattern' => '/(*CRLF)a\Z\r\n(?=)/', 'subject' => "a\r\n"];
        yield 'a grapheme cluster over the newline' => ['pattern' => '/(*ANYCRLF)a\Z\X/', 'subject' => "a\r\n"];
        yield 'no newline after a carriage return under (*CRLF)' => ['pattern' => '/(*CRLF)a\Z\r\N/', 'subject' => "a\r\n"];
        yield 'a dot under s over the newline' => ['pattern' => '/(*CRLF)a\Z.\n/s', 'subject' => "a\r\n"];
        yield 'a dot after an option setting s' => ['pattern' => '/(*ANYCRLF)a\Z(?s).\n?/', 'subject' => "a\r\n"];
        yield 'a mark inside the newline' => ['pattern' => '/(*CRLF)a\Z\r(*MARK:x)\n/', 'subject' => "a\r\n"];
        yield 'an accept after the newline' => ['pattern' => '/(*CRLF)a\Z\r\n(*ACCEPT)b/', 'subject' => "a\r\n"];
        yield 'a repeated newline' => ['pattern' => '/(*ANYCRLF)a\Z(?:\r\n)+/', 'subject' => "a\r\n"];
        yield 'a dollar after the newline' => ['pattern' => '/(*CRLF)a\Z\r\n$/', 'subject' => "a\r\n"];
        yield 'a capital Z after the newline' => ['pattern' => '/(*CRLF)a\Z\r\n\Z/', 'subject' => "a\r\n"];
        yield 'a keep inside the newline' => ['pattern' => '/(*CRLF)a\Z\r\K\n/', 'subject' => "a\r\n"];
        yield 'a word boundary before the newline' => ['pattern' => '/(*ANYCRLF)a\Z\b\r\n/', 'subject' => "a\r\n"];
        yield 'a line start inside the newline under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)a\Z\r^\n/m', 'subject' => "a\r\n"];
        yield 'a line break over the newline' => ['pattern' => '/(*CRLF)a\Z\R/', 'subject' => "a\r\n"];
        yield 'a line start between cr and lf, right after the final newline anchor' => ['pattern' => '/(*ANYCRLF)\r\Z^\n/m', 'subject' => "\r\n"];
        yield 'a control property after the final newline anchor' => ['pattern' => '/(*ANYCRLF)a\Z\p{Cc}/', 'subject' => "a\r"];
        // In a lookahead, the length of what stands before the "^", and of
        // what follows the lookahead up to "\z", must be known.
        yield 'a line start after an optional line feed in a lookahead' => ['pattern' => '/(?=\n?^)a/m', 'subject' => 'a'];
        yield 'a line start in a lookahead, a run of line feeds after' => ['pattern' => '/a(?=\n^)\n+\z/m', 'subject' => "a\n\n"];
        yield 'a line start in a lookahead, an accept after' => ['pattern' => '/a(?=\n^)(*ACCEPT)\n\z/m', 'subject' => "a\nb"];
    }

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
    }

    /**
     * @return list<string>
     */
    private static function subjects(): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= 5; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (self::LETTERS as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            array_push($all, ...$next);
            $layer = $next;
        }

        return $all;
    }
}
