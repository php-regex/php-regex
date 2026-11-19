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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * JavaScript has no possessive quantifier and no atomic group, and no "\R".
 * Where nothing that follows the commitment can take back what it matched,
 * the plain spelling finds the same matches, and is printed. The oracle is
 * PCRE itself: on every subject, the pattern and its plain spelling, read
 * by preg_match(), give the same span. Each literal and attribute was also
 * run under Node 22, exec() and new RegExp("^(?:" + value + ")$", "v"),
 * on the same subjects.
 */
final class JavaScriptCommitmentTest extends TestCase
{
    use PhpUnitForwardCompat;

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideInertCommitments')]
    public function test_prints_a_commitment_that_changes_nothing_plain(string $pattern, string $plain, string $literal, string $attribute, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame(self::span($plain, $subject), self::span($pattern, $subject), $pattern.' on '.json_encode($subject));
        }

        $regex = Regex::create(['cache' => null]);
        $result = $regex->transpile($pattern, 'javascript');
        $this->assertSame($literal, $result->literal);
        $this->assertStringContainsString('without its commitment', implode("\n", $result->notes));

        $this->assertSame($attribute, $regex->transpile($pattern, 'html-pattern')->pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, plain: string, literal: string, attribute: string, subjects: list<string>}>
     */
    public static function provideInertCommitments(): iterable
    {
        yield 'a possessive run to the end' => ['pattern' => '/^\d++$/', 'plain' => '/^\d+$/', 'literal' => '/^\d+(?=\n?$)/', 'attribute' => '^\d+$', 'subjects' => ['12', "12\n", '1a', '', '007']];
        yield 'an atomic run before a dash' => ['pattern' => '/^(?>\d+)-x$/', 'plain' => '/^\d+-x$/', 'literal' => '/^(?:\d+)-x(?=\n?$)/', 'attribute' => '^(?:\d+)-x$', 'subjects' => ['1-x', '12-x', '-x', '1-xx', "1-x\n"]];
        yield 'a line break before a letter' => ['pattern' => '/^a\Rb$/', 'plain' => '/^a(?:\r\n|[\n\x0B\f\r\x85])b$/', 'literal' => '/^a(?:\r\n|[\x0A-\x0D\x85])b(?=\n?$)/', 'attribute' => '^a(?:\r\n|[\x0A-\x0D\x85])b$', 'subjects' => ["a\r\nb", "a\nb", "a\rb", "a\r\rb", 'ab', "a\x0Bb"]];
        yield 'a line break under u' => ['pattern' => '/^a\Rb$/u', 'plain' => '/^a(?:\r\n|[\n\x0B\f\r\x{85}\x{2028}\x{2029}])b$/u', 'literal' => '/^a(?:\r\n|[\x0A-\x0D\x85\u2028\u2029])b(?=\n?$)/u', 'attribute' => '^a(?:\r\n|[\x0A-\x0D\x85\u2028\u2029])b$', 'subjects' => ["a\r\nb", "a\u{2028}b", "a\rb", "a\r\rb"]];
        yield 'an atomic letter in a search' => ['pattern' => '/(?>a)b/', 'plain' => '/ab/', 'literal' => '/(?:a)b/', 'attribute' => '[\s\S]*(?:(?:a)b)[\s\S]*', 'subjects' => ['xab', 'ab', 'b']];
        yield 'a possessive group' => ['pattern' => '/^(?:ab)++c$/', 'plain' => '/^(?:ab)+c$/', 'literal' => '/^(?:ab)+c(?=\n?$)/', 'attribute' => '^(?:ab)+c$', 'subjects' => ['abc', 'ababc', 'abac']];
        yield 'a possessive run in a search' => ['pattern' => '/\d++px/', 'plain' => '/\d+px/', 'literal' => '/\d+px/', 'attribute' => '[\s\S]*(?:\d+px)[\s\S]*', 'subjects' => ['12px', 'x1px', '12p']];
    }

    /**
     * Without u, "\R" takes the byte NEL as "\v" does, where JavaScript
     * reads a character: printed plain, it is warned of as "\v" is.
     */
    #[Test]
    public function test_warns_of_a_line_break_without_u_as_of_a_vertical_space(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^\R$/', 'javascript');

        $this->assertSame('/^(?:\r\n|[\x0A-\x0D\x85])(?=\n?$)/', $result->literal);
        $this->assertStringContainsString('Without the u flag "\R" at offset 1 takes one byte in PCRE', implode("\n", $result->warnings));
    }

    /**
     * A commitment that keeps a match a follower needed otherwise, or one
     * a zero-width follower may refuse where the plain spelling would have
     * stopped earlier, finds other matches: refused, never printed plain.
     */
    #[Test]
    #[DataProvider('provideCommitmentsThatMatter')]
    public function test_refuses_a_commitment_that_changes_the_match(string $pattern, string $plain, string $subject, string $message): void
    {
        $this->assertNotSame(self::span($plain, $subject), self::span($pattern, $subject), $pattern.' on '.json_encode($subject));

        foreach (['javascript', 'html-pattern'] as $target) {
            try {
                Regex::create(['cache' => null])->transpile($pattern, $target);
                $this->fail($pattern.' was printed for '.$target.'.');
            } catch (TranspileException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, plain: string, subject: string, message: string}>
     */
    public static function provideCommitmentsThatMatter(): iterable
    {
        $atomic = 'Atomic groups are not supported in JavaScript.';
        $possessive = 'Possessive quantifiers are not supported in JavaScript.';

        yield 'a shorter branch first' => ['pattern' => '/^(?>a|ab)c$/', 'plain' => '/^(?:a|ab)c$/', 'subject' => 'abc', 'message' => $atomic];
        yield 'a run the follower shares' => ['pattern' => '/^a*+a$/', 'plain' => '/^a*a$/', 'subject' => 'aa', 'message' => $possessive];
        yield 'a newline after a line break' => ['pattern' => "/^\\R\n$/", 'plain' => "/^(?:\r\n|\r|\n)\n$/", 'subject' => "\r\n", 'message' => 'Unsupported character type in JavaScript: \R.'];
        yield 'a lookahead the run feeds' => ['pattern' => '/^a++(?=a)/', 'plain' => '/^a+(?=a)/', 'subject' => 'aa', 'message' => $possessive];
        yield 'a word boundary given back' => ['pattern' => '/^(?>a+)\B/', 'plain' => '/^a+\B/', 'subject' => 'aa', 'message' => $atomic];
        yield 'a line end under m' => ['pattern' => '/^\s++$/m', 'plain' => '/^\s+$/m', 'subject' => " \n x", 'message' => $possessive];
        yield 'a lazy run repeated in an atomic group' => ['pattern' => '/(?>(?:a+?){2})b/', 'plain' => '/(?:a+?){2}b/', 'subject' => 'aaab', 'message' => $atomic];
        yield 'a lazy run repeated possessively' => ['pattern' => '/(?:a+?){2}+b/', 'plain' => '/(?:a+?){2}b/', 'subject' => 'aaab', 'message' => $possessive];
    }

    /**
     * @return array{int, int}|null
     */
    private static function span(string $pattern, string $subject): ?array
    {
        if (1 !== preg_match($pattern, $subject, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$match[0][1], $match[0][1] + \strlen($match[0][0])];
    }
}
