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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Routing;

use PHPRegex\Symfony\Routing\RouteRequirementNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Symfony's RouteCompiler strips a requirement's leading ^ or \A and its
 * trailing $ or \z (Route::sanitizeRequirement()), puts it in a group of
 * its own and matches the route with "{^...$}sD", plus u under the route's
 * utf8 option. The normalized pattern is that pattern, so the engine reads
 * it as the compiled route reads the requirement.
 */
final class RouteRequirementNormalizerTest extends TestCase
{
    private RouteRequirementNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RouteRequirementNormalizer();
    }

    #[Test]
    #[DataProvider('provideRequirements')]
    public function test_normalize_writes_the_pattern_the_route_compiler_matches_with(string $requirement, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($requirement));
    }

    /**
     * @return iterable<string, array{requirement: string, expected: string}>
     */
    public static function provideRequirements(): iterable
    {
        yield 'simple' => ['requirement' => 'test', 'expected' => '{^test$}sD'];
        yield 'class' => ['requirement' => 'test[0-9]+', 'expected' => '{^test[0-9]+$}sD'];
        yield 'leading caret' => ['requirement' => '^test', 'expected' => '{^test$}sD'];
        yield 'trailing dollar' => ['requirement' => 'test$', 'expected' => '{^test$}sD'];
        yield 'both anchors' => ['requirement' => '^test$', 'expected' => '{^test$}sD'];
        yield 'string anchors' => ['requirement' => '\\Atest\\z', 'expected' => '{^test$}sD'];
        yield 'empty' => ['requirement' => '', 'expected' => '{^$}sD'];
        yield 'counted repeat' => ['requirement' => '\\d{2,4}', 'expected' => '{^\\d{2,4}$}sD'];
        yield 'inner anchors kept' => ['requirement' => '^already^anchored$', 'expected' => '{^already^anchored$}sD'];
        yield 'multibyte class' => ['requirement' => 'test[äöü]', 'expected' => '{^test[äöü]$}sD'];
        // A requirement is a fragment, never a delimited pattern: Symfony
        // compiles "/^test$/" to (?P<x>/^test$/).
        yield 'leading slash' => ['requirement' => '/^test$/', 'expected' => '{^/^test$/$}sD'];
        yield 'leading hash' => ['requirement' => '#', 'expected' => '{^#$}sD'];
        yield 'leading percent' => ['requirement' => '%^test$%', 'expected' => '{^%^test$%$}sD'];
        // Braces delimit nothing but themselves, as in the compiled route.
        yield 'hashes and slashes' => ['requirement' => 'a#b/c~d', 'expected' => '{^a#b/c~d$}sD'];
        yield 'escaped hash' => ['requirement' => '^a\\#b$', 'expected' => '{^a\\#b$}sD'];
        yield 'comment under an inline x' => ['requirement' => '(?x)a #c', 'expected' => '{^(?x)a #c$}sD'];
        // sanitizeRequirement() strips a trailing $ even escaped, and a \z
        // only where it first stands.
        yield 'escaped dollar stripped' => ['requirement' => 'a\\$', 'expected' => '{^a\\$}sD'];
        yield 'string end anchor standing twice' => ['requirement' => 'a\\zb\\z', 'expected' => '{^a\\zb\\z$}sD'];
    }

    #[Test]
    public function test_normalize_adds_u_under_the_utf8_option(): void
    {
        $this->assertSame('{^[äöü]+$}sDu', $this->normalizer->normalize('[äöü]+', true));
        $this->assertSame('{^[äöü]+$}sD', $this->normalizer->normalize('[äöü]+'));
    }

    /**
     * Symfony's RouteCompiler puts a requirement in its own group: "en|fr|de"
     * compiles to (?P<x>en|fr|de), so every alternative is anchored.
     */
    #[Test]
    #[DataProvider('provideRequirementsWithAlternatives')]
    public function test_normalize_groups_a_top_level_alternation_as_the_route_compiler_does(string $requirement, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($requirement));
    }

    /**
     * @return iterable<string, array{requirement: string, expected: string}>
     */
    public static function provideRequirementsWithAlternatives(): iterable
    {
        yield 'bare alternation' => ['requirement' => 'en|fr|de', 'expected' => '{^(?:en|fr|de)$}sD'];
        yield 'anchored alternation' => ['requirement' => '^en|fr$', 'expected' => '{^(?:en|fr)$}sD'];
        yield 'string anchors' => ['requirement' => '\\Aen|fr\\z', 'expected' => '{^(?:en|fr)$}sD'];
        // Under an inline x, a comment runs to the newline: its parentheses are text.
        yield 'parenthesis in an x comment' => ['requirement' => "(?x)a # (\n|b", 'expected' => "{^(?:(?x)a # (\n|b)$}sD"];
        yield 'closing parenthesis in an x comment' => ['requirement' => "(?x)a # )\n|b", 'expected' => "{^(?:(?x)a # )\n|b)$}sD"];
        yield 'x turned off before the hash' => ['requirement' => '(?x)(?-x)a#(|b', 'expected' => '{^(?x)(?-x)a#(|b$}sD'];
        yield 'x in a group of its own' => ['requirement' => "(?x:a # (\n)|b", 'expected' => "{^(?:(?x:a # (\n)|b)$}sD"];
        // (?^ resets x: the # after it is a literal.
        yield 'x reset by a caret' => ['requirement' => '(?x)(?^i)a#|b', 'expected' => '{^(?:(?x)(?^i)a#|b)$}sD'];
        yield 'option setting never closed' => ['requirement' => '(?i', 'expected' => '{^(?i$}sD'];
        yield 'empty alternative' => ['requirement' => 'a|', 'expected' => '{^(?:a|)$}sD'];
        yield 'bar after a negated class' => ['requirement' => '[^|]|y', 'expected' => '{^(?:[^|]|y)$}sD'];
        yield 'parenthesis in a comment' => ['requirement' => '(?#(x)|b', 'expected' => '{^(?:(?#(x)|b)$}sD'];
        // [: with a ] before any :] is no POSIX class: the class ends at that ].
        yield 'bracket before a POSIX close' => ['requirement' => '[[:a]|b:]', 'expected' => '{^(?:[[:a]|b:])$}sD'];
        // An alternation that is already grouped, or that is no alternation.
        yield 'grouped' => ['requirement' => '(foo|bar)', 'expected' => '{^(foo|bar)$}sD'];
        yield 'bar in a class' => ['requirement' => '[a|b]x', 'expected' => '{^[a|b]x$}sD'];
        yield 'bar first in a class' => ['requirement' => '[]|]x', 'expected' => '{^[]|]x$}sD'];
        yield 'bar after a POSIX class' => ['requirement' => '[[:alpha:]|]x', 'expected' => '{^[[:alpha:]|]x$}sD'];
        yield 'escaped bar' => ['requirement' => 'a\|b', 'expected' => '{^a\|b$}sD'];
        yield 'quoted bar' => ['requirement' => '\Qa|b\E', 'expected' => '{^\Qa|b\E$}sD'];
        yield 'quoted to the end' => ['requirement' => '\Qa|b', 'expected' => '{^\Qa|b$}sD'];
        yield 'escaped bracket in a class' => ['requirement' => '[\\]|]x', 'expected' => '{^[\\]|]x$}sD'];
        yield 'class never closed' => ['requirement' => '[a|b', 'expected' => '{^[a|b$}sD'];
        yield 'comment never closed' => ['requirement' => '(?#x|b', 'expected' => '{^(?#x|b$}sD'];
        yield 'POSIX class never closed' => ['requirement' => '[[:alpha|b', 'expected' => '{^[[:alpha|b$}sD'];
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: the engine reads the normalized
     * pattern as the compiled route reads the requirement.
     */
    #[Test]
    #[DataProvider('provideRequirementSubjects')]
    public function test_normalize_matches_what_the_compiled_route_matches(string $requirement, string $subject, bool $matches): void
    {
        $this->assertSame($matches ? 1 : 0, preg_match($this->normalizer->normalize($requirement), $subject));
    }

    /**
     * @return iterable<string, array{requirement: string, subject: string, matches: bool}>
     */
    public static function provideRequirementSubjects(): iterable
    {
        yield 'an alternative alone' => ['requirement' => 'en|fr|de', 'subject' => 'fr', 'matches' => true];
        yield 'first alternative with a suffix' => ['requirement' => 'en|fr|de', 'subject' => 'enx', 'matches' => false];
        yield 'last alternative with a prefix' => ['requirement' => 'en|fr|de', 'subject' => 'xde', 'matches' => false];
        yield 'anchored, middle with a prefix' => ['requirement' => '^en|fr$', 'subject' => 'xfr', 'matches' => false];
        yield 'alternative after an x comment' => ['requirement' => "(?x)a # (\n|b", 'subject' => 'ab', 'matches' => false];
        // D: the end holds at the very end only.
        yield 'dollar before a final newline' => ['requirement' => 'a', 'subject' => "a\n", 'matches' => false];
        yield 'string end anchor before a final newline' => ['requirement' => '\\Aa\\z', 'subject' => "a\n", 'matches' => false];
        // s: the dot takes a newline.
        yield 'dot over a newline' => ['requirement' => '.+', 'subject' => "a\nb", 'matches' => true];
        yield 'escaped hash' => ['requirement' => '^a\\#b$', 'subject' => 'a#b', 'matches' => true];
        yield 'comment under an inline x' => ['requirement' => '(?x)a #c', 'subject' => 'a', 'matches' => true];
    }

    /**
     * sanitizeRequirement() strips the escaped $ of "a\$": the compiled
     * route ends with "\)", and the pattern no longer compiles, as here.
     */
    #[Test]
    public function test_a_requirement_ending_with_an_escaped_dollar_does_not_compile(): void
    {
        $this->assertFalse(@preg_match($this->normalizer->normalize('a|b\\$'), ''));
    }
}
