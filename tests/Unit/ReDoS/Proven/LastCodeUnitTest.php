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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Internal\LastCodeUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The last code unit PCRE2 requires in the subject before it tries an
 * attempt, read as its compiler reads it. Every row is pcre2test 10.49's
 * "Last code unit" for the pattern (utf and ucp for u, caseless_restrict for
 * r); the expected character is the one a witness carries, whose last code
 * unit it is.
 */
final class LastCodeUnitTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLastCodeUnits')]
    public function test_last_code_unit_is_the_one_pcre2_requires(string $pattern, ?string $expected): void
    {
        $regex = RegexParser::create()->parse($pattern);
        $unit = LastCodeUnit::of($regex);

        $this->assertSame($expected, null === $unit ? null : Utf8::character($unit, $regex->isUnicode()), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string|null}>
     */
    public static function provideLastCodeUnits(): iterable
    {
        // The last literal of one alternative.
        yield 'star loop before a fat arrow' => ['pattern' => '/\s*=>/', 'expected' => '>'];
        yield 'star loop before a word' => ['pattern' => '/\w*Exception/', 'expected' => 'n'];
        yield 'optional last literal' => ['pattern' => '/xab?/', 'expected' => 'a'];
        yield 'optional literal after the first' => ['pattern' => '/xa?/', 'expected' => null];
        yield 'optional group after the first' => ['pattern' => '/x(?:ab)?/', 'expected' => null];
        yield 'repeated group then an optional literal' => ['pattern' => '/x(?:ab)*c?/', 'expected' => null];
        yield 'literal after a back reference' => ['pattern' => '/x\1?(a)/', 'expected' => 'a'];
        yield 'back reference first' => ['pattern' => '/\1b(a)/', 'expected' => 'a'];
        yield 'subroutine call after a group' => ['pattern' => '/(a)(?1)b/', 'expected' => 'b'];
        yield 'subroutine call first' => ['pattern' => '/(?1)b(a)/', 'expected' => 'a'];
        // The last code unit every alternative ends with.
        yield 'same literal' => ['pattern' => '/a+b|cb/', 'expected' => 'b'];
        yield 'same sign' => ['pattern' => '/\s+=|x=/', 'expected' => '='];
        yield 'classes before the same literal' => ['pattern' => '/[a-z]+;|\d+;/', 'expected' => ';'];
        yield 'dot-star and a repeated group, or the literal alone' => ['pattern' => '/(.*)(?:ab)+|b/', 'expected' => 'b'];
        yield 'lazy dot-star under s, or the literal alone' => ['pattern' => '/.*?(?:ab)+|b/s', 'expected' => 'b'];
        yield 'literal alone, then after another' => ['pattern' => '/b|ab/', 'expected' => 'b'];
        yield 'group of alternatives' => ['pattern' => '/a+(?:b|cb)/', 'expected' => 'b'];
        yield 'repeated group, or the literal after another' => ['pattern' => '/(?:ab)+c|xc/', 'expected' => 'c'];
        yield 'group repeated twice' => ['pattern' => '/a(?:bc){2}|zc/', 'expected' => 'c'];
        yield 'group repeated twice or more' => ['pattern' => '/(?:ab){2,}|xb/', 'expected' => 'b'];
        yield 'literal repeated twice' => ['pattern' => '/x+a{2}|ya/', 'expected' => 'a'];
        yield 'anchored alternative under m' => ['pattern' => '/^a+b|cb/m', 'expected' => 'b'];
        yield 'match start reset' => ['pattern' => '/a+b\K|cb/', 'expected' => 'b'];
        yield 'quoted literal repeated' => ['pattern' => '/\Qab\E+|xb/', 'expected' => 'b'];
        yield 'conditional of two branches' => ['pattern' => '/(a)?(?(1)ab|cb)/', 'expected' => 'b'];
        // Alternatives that end differently, or not at all with a literal.
        yield 'different case' => ['pattern' => '/a+b|cB/', 'expected' => null];
        yield 'optional last literal in one alternative' => ['pattern' => '/a+b?|cb/', 'expected' => null];
        yield 'loop alternative' => ['pattern' => '/(?:x|y)+|ab/', 'expected' => null];
        yield 'conditional of one branch' => ['pattern' => '/(a)?(?(1)ab)/', 'expected' => null];
        // An accepting verb takes the required code unit away.
        yield 'accept' => ['pattern' => '/a+b(*ACCEPT)|cb/', 'expected' => null];
        yield 'accept first' => ['pattern' => '/(*ACCEPT)a/', 'expected' => null];
        // A lookahead gives its last code unit when it has a first one.
        yield 'lookahead' => ['pattern' => '/(?=ab)\w+/', 'expected' => 'b'];
        yield 'lookbehind' => ['pattern' => '/(?<=a)b/', 'expected' => null];
        yield 'negative lookahead' => ['pattern' => '/(?!ab)c/', 'expected' => null];
        // Classes: one character, a character and its other case.
        yield 'negated class first' => ['pattern' => '/[^b]x/', 'expected' => 'x'];
        yield 'negated class last' => ['pattern' => '/x[^b]/', 'expected' => null];
        yield 'class of a letter and its other case' => ['pattern' => '/x[bB]/', 'expected' => 'b'];
        yield 'class of a letter and its other case, upper case first' => ['pattern' => '/x[Cc]/', 'expected' => 'C'];
        yield 'class of a k and its other case' => ['pattern' => '/x[kK]/', 'expected' => null];
        yield 'class of two Latin-1 letters without u' => ['pattern' => '/x[\xE9\xC9]/', 'expected' => null];
        yield 'class of a character without case and a letter under u' => ['pattern' => '/x[€a]/u', 'expected' => null];
        yield 'class of a two-byte letter and its other case under u' => ['pattern' => '/x[éÉ]/u', 'expected' => null];
        // Caseless: the character as written, a flag compared alike.
        yield 'caseless alternatives' => ['pattern' => '/a+b|cb/i', 'expected' => 'b'];
        yield 'caseless sign' => ['pattern' => '/x=/i', 'expected' => '='];
        yield 'caseless and caseful sign' => ['pattern' => '/x=|y(?-i)=/i', 'expected' => null];
        yield 'caseless first letter turned last' => ['pattern' => '/a|xa/i', 'expected' => 'a'];
        yield 'caseless first letter of another case' => ['pattern' => '/A|xa/i', 'expected' => null];
        yield 'caseless group of alternatives' => ['pattern' => '/x(?:B|zB)/i', 'expected' => 'B'];
        yield 'caseless group repeated twice' => ['pattern' => '/(?:b){2}/i', 'expected' => 'b'];
        yield 'caseless k' => ['pattern' => '/xk/i', 'expected' => 'k'];
        yield 'caseless alternatives ending with a k' => ['pattern' => '/[a-z]+k|\d+k/i', 'expected' => 'k'];
        // Under u a caseless k matches the Kelvin sign too: PCRE2 reads it
        // as a property, unless r keeps ASCII and non-ASCII cases apart.
        yield 'caseless k under u' => ['pattern' => '/xk/iu', 'expected' => null];
        yield 'caseless k repeated under u' => ['pattern' => '/xk{2}/iu', 'expected' => null];
        yield 'caseless alternatives ending with a k under u' => ['pattern' => '/[a-z]+k|\d+k/iu', 'expected' => null];
        yield 'caseless k under u and r' => ['pattern' => '/xk/iur', 'expected' => 'k'];
        // Under u, the last code unit of a character of several.
        yield 'two-byte character under u' => ['pattern' => '/xé/u', 'expected' => 'é'];
        yield 'caseless two-byte character under u' => ['pattern' => '/xé/iu', 'expected' => null];
    }
}
