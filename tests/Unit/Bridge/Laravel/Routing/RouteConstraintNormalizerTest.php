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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Routing;

use Illuminate\Routing\Route;
use PHPRegex\Laravel\Routing\RouteConstraintNormalizer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Laravel compiles a route through Symfony's RouteCompiler, always with the
 * utf8 option: the constraint loses a leading ^ or \A and a trailing $ or
 * \z, its own groups stop capturing, it sits in a group named after the
 * parameter, and the path is matched with "{^...$}sDu", the domain with
 * "{^...$}sDiu". The normalized pattern is that pattern without the text of
 * the path around the parameter.
 */
final class RouteConstraintNormalizerTest extends TestCase
{
    private RouteConstraintNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RouteConstraintNormalizer();
    }

    #[Test]
    #[DataProvider('provideConstraints')]
    public function test_normalize_writes_the_pattern_the_router_matches_with(string $constraint, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($this->route(), 'v', $constraint));
    }

    /**
     * @return iterable<string, array{constraint: string, expected: string}>
     */
    public static function provideConstraints(): iterable
    {
        yield 'digits' => ['constraint' => '\d+', 'expected' => '{^(?P<v>\d+)$}sDu'];
        yield 'nested quantifier' => ['constraint' => '(a+)+', 'expected' => '{^(?P<v>(?:a+)+)$}sDu'];
        yield 'both anchors' => ['constraint' => '^[a-z]+$', 'expected' => '{^(?P<v>[a-z]+)$}sDu'];
        yield 'string anchors' => ['constraint' => '\A[a-z]+\z', 'expected' => '{^(?P<v>[a-z]+)$}sDu'];
        yield 'top-level alternation' => ['constraint' => 'en|fr|de', 'expected' => '{^(?P<v>en|fr|de)$}sDu'];
        yield 'alternation in a group' => ['constraint' => '(en|fr)', 'expected' => '{^(?P<v>(?:en|fr))$}sDu'];
        yield 'named group kept' => ['constraint' => '(?<l>en|fr)', 'expected' => '{^(?P<v>(?<l>en|fr))$}sDu'];
        yield 'bar in a class' => ['constraint' => '[a|b]x', 'expected' => '{^(?P<v>[a|b]x)$}sDu'];
        yield 'quoted bar' => ['constraint' => '\Qa|b\E', 'expected' => '{^(?P<v>\Qa|b\E)$}sDu'];
        // A constraint is a fragment, never a delimited pattern: the router
        // compiles "/foo/" to (?P<v>/foo/), slashes included.
        yield 'slashes' => ['constraint' => '/foo/', 'expected' => '{^(?P<v>/foo/)$}sDu'];
        yield 'catch-all' => ['constraint' => '.*', 'expected' => '{^(?P<v>.*)$}sDu'];
        yield 'escaped slash' => ['constraint' => 'a\/b', 'expected' => '{^(?P<v>a\/b)$}sDu'];
        yield 'unclosed group' => ['constraint' => '(a|b', 'expected' => '{^(?P<v>(?:a|b)$}sDu'];
        // The braces stand in the pattern as the router writes them.
        yield 'brace in a class' => ['constraint' => '[{]', 'expected' => '{^(?P<v>[{])$}sDu'];
        yield 'counted repeat' => ['constraint' => '\d{2,4}', 'expected' => '{^(?P<v>\d{2,4})$}sDu'];
        // sanitizeRequirement() strips a trailing $ even escaped: the
        // backslash left escapes the ) of the parameter's group.
        yield 'escaped dollar' => ['constraint' => 'a\$', 'expected' => '{^(?P<v>a\)$}sDu'];
        // The router inserts ?: after every ( no backslash escapes, in a
        // class too.
        yield 'parenthesis in a class' => ['constraint' => '[(]a', 'expected' => '{^(?P<v>[(?:]a)$}sDu'];
    }

    /**
     * The router's own regex, the text of the path around the parameter
     * taken out, is the normalized pattern.
     */
    #[Test]
    #[DataProvider('provideRouterConstraints')]
    public function test_normalize_is_the_regex_the_router_compiles(string $constraint): void
    {
        $route = (new Route(['GET'], 'x/{v}', static fn (): string => ''))->where('v', $constraint);
        $compiled = $route->toSymfonyRoute()->compile()->getRegex();

        $this->assertSame(str_replace('{^/x/', '{^', $compiled), $this->normalizer->normalize($route, 'v', $constraint));
    }

    /**
     * @return iterable<string, array{constraint: string}>
     */
    public static function provideRouterConstraints(): iterable
    {
        foreach (self::provideConstraints() as $name => $row) {
            yield $name => ['constraint' => $row['constraint']];
        }
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49, and Laravel's router: each of these
     * passes between slashes, and the compiled route does not compile.
     */
    #[Test]
    #[DataProvider('provideConstraintsTheRouterRefuses')]
    public function test_a_constraint_the_router_cannot_compile_is_invalid(string $constraint): void
    {
        $normalized = $this->normalizer->normalize($this->route(), 'v', $constraint);
        $route = (new Route(['GET'], 'x/{v}', static fn (): string => ''))->where('v', $constraint);

        $this->assertNotFalse(@preg_match('/'.addcslashes($constraint, '/').'/u', ''), 'control');
        $this->assertFalse(@preg_match($route->toSymfonyRoute()->compile()->getRegex(), ''), 'oracle');
        $this->assertFalse(@preg_match($normalized, ''));
        $this->assertFalse(Regex::create()->validate($normalized)->isValid);
    }

    /**
     * @return iterable<string, array{constraint: string}>
     */
    public static function provideConstraintsTheRouterRefuses(): iterable
    {
        yield 'unbalanced brace in a class' => ['constraint' => '[{]'];
        yield 'unbalanced closing brace' => ['constraint' => 'a}'];
        yield 'escaped dollar' => ['constraint' => 'a\$'];
        // The second group no longer captures: \2 names no group.
        yield 'backreference to a group that stops capturing' => ['constraint' => '(a)(b)\2'];
    }

    #[Test]
    public function test_normalize_adds_i_for_a_domain_parameter(): void
    {
        $route = new Route(['GET'], '/x/{v}', static fn (): string => '');
        $route->domain('{account}.example.com');

        $this->assertSame('{^(?P<account>[a-z]+)$}sDiu', $this->normalizer->normalize($route, 'account', '[a-z]+'));
        $this->assertSame('{^(?P<v>[a-z]+)$}sDu', $this->normalizer->normalize($route, 'v', '[a-z]+'));
    }

    /**
     * A constraint on a parameter the path never names is not compiled by
     * the router: it is read in a group all the same, unnamed where the
     * name is none PCRE takes.
     */
    #[Test]
    public function test_a_parameter_that_is_no_group_name_gets_an_unnamed_group(): void
    {
        $this->assertSame('{^(\d+)$}sDu', $this->normalizer->normalize($this->route(), 'my-id', '\d+'));
        $this->assertSame('{^(\d+)$}sDu', $this->normalizer->normalize($this->route(), '1st', '\d+'));
        $this->assertSame('{^(\d+)$}sDu', $this->normalizer->normalize($this->route(), str_repeat('a', 33), '\d+'));
        $this->assertSame('{^(?P<'.str_repeat('a', 32).'>\d+)$}sDu', $this->normalizer->normalize($this->route(), str_repeat('a', 32), '\d+'));
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: the engine reads the normalized
     * pattern as the compiled route reads the constraint.
     */
    #[Test]
    #[DataProvider('provideConstraintSubjects')]
    public function test_normalize_matches_what_the_compiled_route_matches(string $constraint, string $subject, bool $matches): void
    {
        $this->assertSame($matches ? 1 : 0, preg_match($this->normalizer->normalize($this->route(), 'v', $constraint), $subject));
    }

    /**
     * @return iterable<string, array{constraint: string, subject: string, matches: bool}>
     */
    public static function provideConstraintSubjects(): iterable
    {
        yield 'digits, with a suffix' => ['constraint' => '\d+', 'subject' => '12a', 'matches' => false];
        yield 'an alternative alone' => ['constraint' => 'en|fr|de', 'subject' => 'fr', 'matches' => true];
        yield 'first alternative with a suffix' => ['constraint' => 'en|fr|de', 'subject' => 'enx', 'matches' => false];
        yield 'last alternative with a prefix' => ['constraint' => 'en|fr|de', 'subject' => 'xde', 'matches' => false];
        // D: the end holds at the very end only.
        yield 'dollar before a final newline' => ['constraint' => 'a', 'subject' => "a\n", 'matches' => false];
        // s: the dot takes a newline.
        yield 'dot over a newline' => ['constraint' => '.+', 'subject' => "a\nb", 'matches' => true];
        // u: a class reads characters, not bytes.
        yield 'multibyte class' => ['constraint' => '[é]', 'subject' => 'é', 'matches' => true];
        yield 'slash in a quote' => ['constraint' => '\Q/\E', 'subject' => '/', 'matches' => true];
        yield 'escaped brace' => ['constraint' => '\{', 'subject' => '{', 'matches' => true];
    }

    /**
     * The unanchored pattern finds "aaa" in the subject at once; anchored as
     * the router reads it, (a+)+ backtracks out of the engine's limit.
     */
    #[Test]
    public function test_normalize_keeps_the_backtracking_the_router_meets(): void
    {
        $subject = str_repeat('a', 30).'!';

        $this->assertFalse(@preg_match($this->normalizer->normalize($this->route(), 'v', '(a+)+'), $subject));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    private function route(): Route
    {
        return new Route(['GET'], '/x/{v}', static fn (): string => '');
    }
}
