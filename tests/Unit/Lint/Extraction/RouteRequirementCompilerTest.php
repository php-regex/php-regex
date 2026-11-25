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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\RouteRequirementCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

/**
 * The pattern Symfony's route compiler, which Laravel's router runs too,
 * matches a route requirement with, without the text of the path around
 * the parameter.
 */
final class RouteRequirementCompilerTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSanitized')]
    public function test_sanitize_strips_one_anchor_at_each_end(string $requirement, string $expected): void
    {
        $this->assertSame($expected, RouteRequirementCompiler::sanitize($requirement));
    }

    /**
     * @return iterable<string, array{requirement: string, expected: string}>
     */
    public static function provideSanitized(): iterable
    {
        yield 'no anchor' => ['requirement' => 'a', 'expected' => 'a'];
        yield 'caret and dollar' => ['requirement' => '^a$', 'expected' => 'a'];
        yield 'string anchors' => ['requirement' => '\\Aa\\z', 'expected' => 'a'];
        yield 'one copy only' => ['requirement' => '^^a$$', 'expected' => '^a$'];
        yield 'an escaped dollar goes too' => ['requirement' => 'a\\$', 'expected' => 'a\\'];
        yield 'a z standing twice stays' => ['requirement' => 'a\\zb\\z', 'expected' => 'a\\zb\\z'];
    }

    #[Test]
    #[DataProvider('provideCompiled')]
    public function test_compile_writes_the_group_and_the_flags_the_compiler_writes(string $requirement, string $parameter, bool $utf8, bool $host, string $expected): void
    {
        $this->assertSame($expected, RouteRequirementCompiler::compile($requirement, $parameter, $utf8, $host));
    }

    /**
     * @return iterable<string, array{requirement: string, parameter: string, utf8: bool, host: bool, expected: string}>
     */
    public static function provideCompiled(): iterable
    {
        yield 'path' => ['requirement' => 'a', 'parameter' => 'x', 'utf8' => false, 'host' => false, 'expected' => '{^(?P<x>a)$}sD'];
        yield 'path under utf8' => ['requirement' => 'a', 'parameter' => 'x', 'utf8' => true, 'host' => false, 'expected' => '{^(?P<x>a)$}sDu'];
        yield 'host' => ['requirement' => 'a', 'parameter' => 'x', 'utf8' => false, 'host' => true, 'expected' => '{^(?P<x>a)$}sDi'];
        yield 'host under utf8' => ['requirement' => 'a', 'parameter' => 'x', 'utf8' => true, 'host' => true, 'expected' => '{^(?P<x>a)$}sDiu'];
        yield 'held anchors kept' => ['requirement' => '^a$', 'parameter' => 'x', 'utf8' => false, 'host' => false, 'expected' => '{^(?P<x>^a$)$}sD'];
        yield 'group made non-capturing' => ['requirement' => '(a|b)', 'parameter' => 'x', 'utf8' => false, 'host' => false, 'expected' => '{^(?P<x>(?:a|b))$}sD'];
        yield 'parenthesis in a class' => ['requirement' => '[(]a', 'parameter' => 'x', 'utf8' => false, 'host' => false, 'expected' => '{^(?P<x>[(?:]a)$}sD'];
        yield 'escaped parenthesis' => ['requirement' => 'a\\(b', 'parameter' => 'x', 'utf8' => false, 'host' => false, 'expected' => '{^(?P<x>a\\(b)$}sD'];
        yield 'a name PCRE refuses' => ['requirement' => 'a', 'parameter' => '1x', 'utf8' => false, 'host' => false, 'expected' => '{^(a)$}sD'];
        yield 'a name past 32 bytes' => ['requirement' => 'a', 'parameter' => str_repeat('x', 33), 'utf8' => false, 'host' => false, 'expected' => '{^(a)$}sD'];
    }

    /**
     * symfony/routing makes a named group of a requirement non-capturing
     * too since 6.4.51, 7.4.24 and 8.1.12, its name dropped, and keeps it
     * before: the compiler writes the requirement as the release named
     * does, and the installed one writes what its release says. A
     * lookbehind, a backreference or a call by name opens no group.
     */
    #[Test]
    #[DataProvider('provideNamedGroups')]
    public function test_compile_drops_the_name_of_a_group_unless_the_release_keeps_it(string $requirement, string $dropped, string $kept): void
    {
        $installed = (new Route('/{x}', [], ['x' => $requirement]))->compile()->getRegex();
        $keeps = '{^/(?P<x>'.$kept.')$}sD' === $installed;

        $this->assertSame($keeps ? '{^/(?P<x>'.$kept.')$}sD' : '{^/(?P<x>'.$dropped.')$}sD', $installed, 'oracle');
        $this->assertSame('{^(?P<x>'.$dropped.')$}sD', RouteRequirementCompiler::compile($requirement, 'x', false, false));
        $this->assertSame('{^(?P<x>'.$dropped.')$}sD', RouteRequirementCompiler::compile($requirement, 'x', false, false, false));
        $this->assertSame('{^(?P<x>'.$kept.')$}sD', RouteRequirementCompiler::compile($requirement, 'x', false, false, true));
    }

    /**
     * @return iterable<string, array{requirement: string, dropped: string, kept: string}>
     */
    public static function provideNamedGroups(): iterable
    {
        yield 'angle brackets' => ['requirement' => '(?<l>en|fr)', 'dropped' => '(?:en|fr)', 'kept' => '(?<l>en|fr)'];
        yield 'P and angle brackets' => ['requirement' => '(?P<l>en|fr)', 'dropped' => '(?:en|fr)', 'kept' => '(?P<l>en|fr)'];
        yield 'quotes' => ['requirement' => "(?'l'en|fr)", 'dropped' => '(?:en|fr)', 'kept' => "(?'l'en|fr)"];
        yield 'one after another' => ['requirement' => "a(?P<x>b)(?<y>c)(?'z'd)", 'dropped' => 'a(?:b)(?:c)(?:d)', 'kept' => "a(?P<x>b)(?<y>c)(?'z'd)"];
        yield 'a group inside' => ['requirement' => '(?<x>(b))', 'dropped' => '(?:(?:b))', 'kept' => '(?<x>(?:b))'];
        yield 'in a class' => ['requirement' => '[(?<x>]', 'dropped' => '[(?:]', 'kept' => '[(?<x>]'];
        yield 'escaped parenthesis' => ['requirement' => '\\(?<x>a)', 'dropped' => '\\(?<x>a)', 'kept' => '\\(?<x>a)'];
        yield 'lookbehinds' => ['requirement' => '(?<=a)b(?<!c)', 'dropped' => '(?<=a)b(?<!c)', 'kept' => '(?<=a)b(?<!c)'];
        yield 'backreferences by name' => ['requirement' => '(?<n>a)(?P=n)\\k<n>', 'dropped' => '(?:a)(?P=n)\\k<n>', 'kept' => '(?<n>a)(?P=n)\\k<n>'];
        yield 'call by name' => ['requirement' => '(?<n>a)(?P>n)', 'dropped' => '(?:a)(?P>n)', 'kept' => '(?<n>a)(?P>n)'];
        yield 'name never closed' => ['requirement' => '(?<n', 'dropped' => '(?<n', 'kept' => '(?<n'];
    }
}
