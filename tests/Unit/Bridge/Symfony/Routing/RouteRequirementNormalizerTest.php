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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

/**
 * Symfony's RouteCompiler strips a requirement's leading ^ or \A and its
 * trailing $ or \z (Route::sanitizeRequirement()), turns its groups into
 * non-capturing ones, puts it in a group named after the parameter and
 * matches the path with "{^...$}sD", the host with "{^...$}sDi", plus u
 * under the route's utf8 option. The normalized pattern is that pattern
 * without the text of the path around the parameter.
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
        $this->assertSame($expected, $this->normalizer->normalize($requirement, 'x'));
    }

    /**
     * @return iterable<string, array{requirement: string, expected: string}>
     */
    public static function provideRequirements(): iterable
    {
        yield 'simple' => ['requirement' => 'test', 'expected' => '{^(?P<x>test)$}sD'];
        yield 'class' => ['requirement' => 'test[0-9]+', 'expected' => '{^(?P<x>test[0-9]+)$}sD'];
        yield 'leading caret' => ['requirement' => '^test', 'expected' => '{^(?P<x>test)$}sD'];
        yield 'trailing dollar' => ['requirement' => 'test$', 'expected' => '{^(?P<x>test)$}sD'];
        yield 'both anchors' => ['requirement' => '^test$', 'expected' => '{^(?P<x>test)$}sD'];
        yield 'string anchors' => ['requirement' => '\\Atest\\z', 'expected' => '{^(?P<x>test)$}sD'];
        yield 'counted repeat' => ['requirement' => '\\d{2,4}', 'expected' => '{^(?P<x>\\d{2,4})$}sD'];
        yield 'inner anchors kept' => ['requirement' => '^already^anchored$', 'expected' => '{^(?P<x>already^anchored)$}sD'];
        // A requirement is a fragment, never a delimited pattern: Symfony
        // compiles "/^test$/" to (?P<x>/^test$/).
        yield 'leading slash' => ['requirement' => '/^test$/', 'expected' => '{^(?P<x>/^test$/)$}sD'];
        yield 'leading hash' => ['requirement' => '#', 'expected' => '{^(?P<x>#)$}sD'];
        yield 'leading percent' => ['requirement' => '%^test$%', 'expected' => '{^(?P<x>%^test$%)$}sD'];
        yield 'hashes and slashes' => ['requirement' => 'a#b/c~d', 'expected' => '{^(?P<x>a#b/c~d)$}sD'];
        yield 'escaped hash' => ['requirement' => '^a\\#b$', 'expected' => '{^(?P<x>a\\#b)$}sD'];
        yield 'comment under an inline x' => ['requirement' => '(?x)a #c', 'expected' => '{^(?P<x>(?x)a #c)$}sD'];
        // The parameter's group anchors every alternative.
        yield 'bare alternation' => ['requirement' => 'en|fr|de', 'expected' => '{^(?P<x>en|fr|de)$}sD'];
        yield 'anchored alternation' => ['requirement' => '^en|fr$', 'expected' => '{^(?P<x>en|fr)$}sD'];
        // The compiler inserts ?: after every ( no backslash escapes, in a
        // class or an x comment too.
        yield 'grouped alternation' => ['requirement' => '(foo|bar)', 'expected' => '{^(?P<x>(?:foo|bar))$}sD'];
        yield 'named group kept' => ['requirement' => '(?<l>en|fr)', 'expected' => '{^(?P<x>(?<l>en|fr))$}sD'];
        yield 'parenthesis in a class' => ['requirement' => '[(]a', 'expected' => '{^(?P<x>[(?:]a)$}sD'];
        yield 'parenthesis in an x comment' => ['requirement' => "(?x)a # (\n|b", 'expected' => "{^(?P<x>(?x)a # (?:\n|b)$}sD"];
        yield 'escaped parenthesis' => ['requirement' => 'a\\(b', 'expected' => '{^(?P<x>a\\(b)$}sD'];
        // The braces stand in the pattern as the compiler writes them.
        yield 'brace in a class' => ['requirement' => '[{]', 'expected' => '{^(?P<x>[{])$}sD'];
        // sanitizeRequirement() strips a trailing $ even escaped: the
        // backslash left escapes the ) of the parameter's group.
        yield 'escaped dollar stripped' => ['requirement' => 'a\\$', 'expected' => '{^(?P<x>a\\)$}sD'];
        yield 'string end anchor standing twice' => ['requirement' => 'a\\zb\\z', 'expected' => '{^(?P<x>a\\zb\\z)$}sD'];
    }

    /**
     * The compiler's own regex, the text of the path around the parameter
     * taken out, is the normalized pattern.
     */
    #[Test]
    #[DataProvider('provideCompiledRequirements')]
    public function test_normalize_is_the_regex_the_route_compiler_writes(string $requirement): void
    {
        $compiled = (new Route('/a/{x}', [], ['x' => $requirement]))->compile()->getRegex();

        $this->assertSame(str_replace('{^/a/', '{^', $compiled), $this->normalizer->normalize($requirement, 'x'));
    }

    /**
     * @return iterable<string, array{requirement: string}>
     */
    public static function provideCompiledRequirements(): iterable
    {
        foreach (self::provideRequirements() as $name => $row) {
            yield $name => ['requirement' => $row['requirement']];
        }
    }

    #[Test]
    public function test_normalize_adds_u_under_the_utf8_option(): void
    {
        $this->assertSame('{^(?P<x>[äöü]+)$}sDu', $this->normalizer->normalize('[äöü]+', 'x', true));
        $this->assertSame('{^(?P<x>[äöü]+)$}sD', $this->normalizer->normalize('[äöü]+', 'x'));
        $this->assertSame(
            (new Route('/{x}', [], ['x' => '[äöü]+'], ['utf8' => true]))->compile()->getRegex(),
            str_replace('{^', '{^/', $this->normalizer->normalize('[äöü]+', 'x', true)),
        );
    }

    #[Test]
    public function test_normalize_adds_i_for_a_host_parameter(): void
    {
        $route = new Route('/', [], ['sub' => '[a-z]+'], ['utf8' => true], '{sub}.example.com');

        $this->assertSame('{^(?P<sub>[a-z]+)$}sDiu', $this->normalizer->normalize('[a-z]+', 'sub', true, true));
        $this->assertSame(
            str_replace('\.example\.com$', '$', (string) $route->compile()->getHostRegex()),
            $this->normalizer->normalize('[a-z]+', 'sub', true, true),
        );
    }

    /**
     * A requirement on a parameter the path never names is not compiled by
     * the router: it is read in a group all the same, unnamed where the
     * name is none PCRE takes.
     */
    #[Test]
    public function test_a_parameter_that_is_no_group_name_gets_an_unnamed_group(): void
    {
        $this->assertSame('{^(\d+)$}sD', $this->normalizer->normalize('\d+', 'my-id'));
        $this->assertSame('{^(\d+)$}sD', $this->normalizer->normalize('\d+', '1st'));
        $this->assertSame('{^(\d+)$}sD', $this->normalizer->normalize('\d+', str_repeat('a', 33)));
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49, and Symfony's router: each of these
     * passes between slashes, and the compiled route does not compile.
     */
    #[Test]
    #[DataProvider('provideRequirementsTheRouterRefuses')]
    public function test_a_requirement_the_router_cannot_compile_is_invalid(string $requirement): void
    {
        $normalized = $this->normalizer->normalize($requirement, 'x');

        $this->assertNotFalse(@preg_match('/'.addcslashes($requirement, '/').'/', ''), 'control');
        $this->assertFalse(@preg_match((new Route('/a/{x}', [], ['x' => $requirement]))->compile()->getRegex(), ''), 'oracle');
        $this->assertFalse(@preg_match($normalized, ''));
        $this->assertFalse(Regex::create()->validate($normalized)->isValid);
    }

    /**
     * @return iterable<string, array{requirement: string}>
     */
    public static function provideRequirementsTheRouterRefuses(): iterable
    {
        yield 'unbalanced brace in a class' => ['requirement' => '[{]'];
        yield 'escaped dollar' => ['requirement' => 'a\\$'];
        yield 'escaped dollar after an alternation' => ['requirement' => 'a|b\\$'];
        // Under x, the comment runs to the end and takes the group's ) with it.
        yield 'comment under an inline x' => ['requirement' => '(?x)a #c'];
        // The second group no longer captures: \2 names no group.
        yield 'backreference to a group that stops capturing' => ['requirement' => '(a)(b)\\2'];
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: the engine reads the normalized
     * pattern as the compiled route reads the requirement.
     */
    #[Test]
    #[DataProvider('provideRequirementSubjects')]
    public function test_normalize_matches_what_the_compiled_route_matches(string $requirement, string $subject, bool $matches): void
    {
        $this->assertSame($matches ? 1 : 0, preg_match($this->normalizer->normalize($requirement, 'x'), $subject));
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
        // D: the end holds at the very end only.
        yield 'dollar before a final newline' => ['requirement' => 'a', 'subject' => "a\n", 'matches' => false];
        yield 'string end anchor before a final newline' => ['requirement' => '\\Aa\\z', 'subject' => "a\n", 'matches' => false];
        // s: the dot takes a newline.
        yield 'dot over a newline' => ['requirement' => '.+', 'subject' => "a\nb", 'matches' => true];
        yield 'escaped hash' => ['requirement' => '^a\\#b$', 'subject' => 'a#b', 'matches' => true];
        yield 'escaped brace' => ['requirement' => '\\{', 'subject' => '{', 'matches' => true];
    }
}
