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

namespace PHPRegex\Tests\Unit\Visitor;

use PHPRegex\Optimizer\Modernizer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModernizerTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_modernizes_digit_range(): void
    {
        $original = '/[0-9]+/';
        $modernized = $this->modernize($original);

        $this->assertSame('/\d+/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    public function test_removes_unnecessary_escaping(): void
    {
        $original = '/\@name\:/';
        $modernized = $this->modernize($original);

        $this->assertSame('/@name:/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    public function test_modernizes_backref(): void
    {
        $original = '/(a)\1/';
        $modernized = $this->modernize($original);

        $this->assertSame('/(a)\g{1}/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    /**
     * Every PCRE2 from 10.32 to 10.49 matches "\N" and "\g{N}" alike: caseless,
     * forward, in a lookbehind, past nine groups, in a branch-reset group.
     */
    #[DataProvider('provideNumericBackrefs')]
    public function test_numeric_backref_becomes_braced_reference(string $pattern, string $expected, string $subject): void
    {
        $modernized = $this->modernize($pattern);

        $this->assertSame($expected, $modernized);
        $this->assertSame(preg_match($pattern, $subject, $original), preg_match($modernized, $subject, $rewritten));
        $this->assertSame($original, $rewritten);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subject: string}>
     */
    public static function provideNumericBackrefs(): iterable
    {
        yield 'caseless' => ['pattern' => '/(a)\1/i', 'expected' => '/(a)\g{1}/i', 'subject' => 'aA'];
        yield 'forward reference' => ['pattern' => '/(?:\1b|(a))+/', 'expected' => '/(?:\g{1}b|(a))+/', 'subject' => 'aab'];
        yield 'in a lookbehind' => ['pattern' => '/(a)(?<=\1)/', 'expected' => '/(a)(?<=\g{1})/', 'subject' => 'a'];
        yield 'eleventh group' => ['pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)\11/', 'expected' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)\g{11}/', 'subject' => 'abcdefghijkk'];
        yield 'branch reset' => ['pattern' => '/(?|(a)|(b))\1/', 'expected' => '/(?|(a)|(b))\g{1}/', 'subject' => 'bb'];
        yield 'quantified' => ['pattern' => '/(a)\1{2,}+/', 'expected' => '/(a)\g{1}{2,}+/', 'subject' => 'aaaa'];
    }

    /**
     * "(?(\g{1})b)" is no condition: PCRE2 refuses it. The reference of a
     * condition keeps the spelling it was written with.
     */
    #[DataProvider('provideConditionReferences')]
    public function test_condition_reference_is_left_as_written(string $pattern): void
    {
        $modernized = $this->modernize($pattern);

        $this->assertSame($pattern, $modernized);
        $this->assertNotFalse(@preg_match($modernized, ''));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideConditionReferences(): iterable
    {
        yield 'absolute' => ['pattern' => '/(a)?(?(1)b|c)/'];
        yield 'relative forward' => ['pattern' => '/(?(+1)b|c)(a)/'];
        yield 'relative backward' => ['pattern' => '/(a)?(?(-1)b|c)/'];
        yield 'recursion' => ['pattern' => '/(a(?(R1)b|c))/'];
    }

    public function test_complex_modernization(): void
    {
        $original = '/^[0-9]+\-[a-z]+\@(?:gmail)\.com$/';
        $modernized = $this->modernize($original);

        // Expected: ^\d+-[a-z]+@gmail\.com$
        $this->assertSame('/^\d+-[a-z]+@gmail\.com$/', $modernized);
        $this->assertMatchesOriginalBehavior($original, $modernized);
    }

    /**
     * "\s" matches HT, LF, VT, FF, CR and the space on every PCRE2 from 10.32
     * to 10.49, and nothing else below 256; under "/u" or "(*UCP)" it also
     * matches U+0085, U+00A0, U+2028 and U+3000.
     */
    #[DataProvider('provideWhitespaceClasses')]
    public function test_whitespace_class_becomes_shorthand_only_when_it_matches_alike(string $pattern, bool $shorthand): void
    {
        $modernized = $this->modernize($pattern);

        $this->assertSame($shorthand, str_contains($modernized, '\s'), $modernized);
        $subjects = array_map(chr(...), range(0, 255));
        if (str_ends_with($pattern, 'u') || str_contains($pattern, '(*UCP)')) {
            $subjects = ["\u{85}", "\u{A0}", "\u{2028}", "\u{3000}", ' ', "\v"];
        }

        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($modernized, $subject), \sprintf('%s and %s on %s', $pattern, $modernized, bin2hex($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, shorthand: bool}>
     */
    public static function provideWhitespaceClasses(): iterable
    {
        yield 'six members' => ['pattern' => '/[\t\n\x0B\f\r ]/', 'shorthand' => true];
        yield 'six members in another order' => ['pattern' => '/[ \r\f\x0B\n\t]/', 'shorthand' => true];
        yield 'a range and the space' => ['pattern' => '/[\t-\r ]/', 'shorthand' => true];
        yield 'raw controls and the space' => ['pattern' => "/[\t\n\r\f\v ]/", 'shorthand' => true];
        yield 'raw controls without the space' => ['pattern' => "/[\t\n\r\f\v]/", 'shorthand' => false];
        yield 'without the vertical tab' => ['pattern' => '/[\t\n\f\r ]/', 'shorthand' => false];
        yield 'escaped v is vertical space' => ['pattern' => '/[\t\n\v\f\r ]/', 'shorthand' => false];
        yield 'one more member' => ['pattern' => '/[\t\n\x0B\f\r a]/', 'shorthand' => false];
        yield 'under u' => ['pattern' => '/[\t\n\x0B\f\r ]/u', 'shorthand' => false];
        yield 'under UCP' => ['pattern' => '/(*UCP)[\t\n\x0B\f\r ]/', 'shorthand' => false];
        yield 'negated' => ['pattern' => '/[^\t\n\x0B\f\r ]/', 'shorthand' => false];
    }

    private function assertMatchesOriginalBehavior(string $original, string $modernized): void
    {
        // Simple test: both should match or not match the same test strings
        $testStrings = ['123', 'abc', '123-abc@gmail.com', '@name:', 'aa'];

        foreach ($testStrings as $test) {
            $originalMatches = preg_match($original, $test) > 0;
            $modernizedMatches = preg_match($modernized, $test) > 0;
            $this->assertSame($originalMatches, $modernizedMatches, "Mismatch for test string: $test");
        }
    }

    private function modernize(string $pattern): string
    {
        $ast = $this->regexService->parse($pattern);
        $modernized = $ast->accept(new Modernizer());

        return $modernized->accept(new PatternPrinter());
    }
}
