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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Laravel compiles a route through Symfony's RouteCompiler, always with the
 * utf8 option: the constraint loses a leading ^ or \A and a trailing $ or
 * \z, sits in a group of its own, and the path is matched with
 * "{^...$}sDu", the domain with "{^...$}sDiu". The normalized pattern is
 * read by the engine as the compiled route reads the constraint.
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
        yield 'digits' => ['constraint' => '\d+', 'expected' => '/^\d+$/sDu'];
        yield 'nested quantifier' => ['constraint' => '(a+)+', 'expected' => '/^(a+)+$/sDu'];
        yield 'both anchors' => ['constraint' => '^[a-z]+$', 'expected' => '/^[a-z]+$/sDu'];
        yield 'string anchors' => ['constraint' => '\A[a-z]+\z', 'expected' => '/^[a-z]+$/sDu'];
        yield 'top-level alternation' => ['constraint' => 'en|fr|de', 'expected' => '/^(?:en|fr|de)$/sDu'];
        yield 'alternation in a group' => ['constraint' => '(en|fr)', 'expected' => '/^(en|fr)$/sDu'];
        yield 'bar in a class' => ['constraint' => '[a|b]x', 'expected' => '/^[a|b]x$/sDu'];
        yield 'quoted bar' => ['constraint' => '\Qa|b\E', 'expected' => '/^\Qa|b\E$/sDu'];
        // A constraint is a fragment, never a delimited pattern: the router
        // compiles "/foo/" to (?P<v>/foo/), slashes included.
        yield 'slashes' => ['constraint' => '/foo/', 'expected' => '#^/foo/$#sDu'];
        yield 'catch-all' => ['constraint' => '.*', 'expected' => '/^.*$/sDu'];
        yield 'escaped slash' => ['constraint' => 'a\/b', 'expected' => '#^a\/b$#sDu'];
        yield 'slash in a quote' => ['constraint' => '\Q/\E', 'expected' => '#^\Q/\E$#sDu'];
        // Every candidate delimiter in the constraint: the bare ones are
        // escaped, an escaped one is left as it is.
        yield 'every delimiter' => ['constraint' => '/#~!@%`\/', 'expected' => '/^\/#~!@%`\/$/sDu'];
        // A constraint that does not parse is left ungrouped: the error is
        // the lint's to report.
        yield 'unclosed group' => ['constraint' => '(a|b', 'expected' => '/^(a|b$/sDu'];
    }

    #[Test]
    public function test_normalize_adds_i_for_a_domain_parameter(): void
    {
        $route = new Route(['GET'], '/x/{v}', static fn (): string => '');
        $route->domain('{account}.example.com');

        $this->assertSame('/^[a-z]+$/sDiu', $this->normalizer->normalize($route, 'account', '[a-z]+'));
        $this->assertSame('/^[a-z]+$/sDu', $this->normalizer->normalize($route, 'v', '[a-z]+'));
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
        yield 'escaped delimiters' => ['constraint' => '/#~!@%`\/', 'subject' => '/#~!@%`/', 'matches' => true];
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
