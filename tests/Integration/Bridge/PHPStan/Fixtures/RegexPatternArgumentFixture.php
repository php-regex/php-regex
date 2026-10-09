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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures\RegexPatternArgument;

use JetBrains\PhpStorm\Language;
use PHPRegex\Parser\Attribute\RegexPattern;

function matches(string $subject, #[RegexPattern] string $regex): bool
{
    return 1 === preg_match($regex, $subject);
}

function grep(#[Language('RegExp')] string $pattern, string $subject): bool
{
    return 1 === preg_match($pattern, $subject);
}

function parse(#[Language('JSON')] string $json): mixed
{
    return json_decode($json);
}

function other(#[\Other\RegexPattern] string $regex, #[\Other\Language('RegExp')] string $pattern): string
{
    return $regex.$pattern;
}

function any(string $subject, #[RegexPattern] string ...$patterns): bool
{
    return [] !== $patterns && '' !== $subject;
}

final class Str
{
    public static function matches(#[RegexPattern] string $regex, string $subject): bool
    {
        return 1 === preg_match($regex, $subject);
    }

    public function contains(string $subject, #[RegexPattern] string $regex): bool
    {
        return 1 === preg_match($regex, $subject);
    }
}

final readonly class Matcher
{
    public function __construct(#[RegexPattern] public string $regex) {}
}

final class Calls
{
    public function calls(Str $str, ?Str $maybe, string $variable, bool $flag): void
    {
        matches('x', '/(foo/');
        Str::matches('/(foo/', 'x');
        $str->contains('x', '/(foo/');
        $maybe?->contains('x', '/(foo/');
        new Matcher('/(foo/');
        matches(regex: '/(foo/', subject: 'x');
        matches(subject: '/(foo/', regex: '/foo/');
        grep('/(foo/', 'x');
        any('x', '/foo/', '/(foo/');
        matches('x', $flag ? '/foo/' : '/(bar/');

        // Not a pattern parameter, or not a constant pattern: nothing to read.
        parse('/(foo/');
        other('/(foo/', '/(foo/');
        matches('x', $variable);
        matches('x', '/foo/');
        $matcher = matches(...);
        preg_match('/(foo/', 'x');
        matches(...['x', '/(foo/']);
    }
}

class Base
{
    public function __construct(#[RegexPattern] public string $regex) {}

    public static function check(#[RegexPattern] string $regex): bool
    {
        return '' !== $regex;
    }
}

final class Child extends Base
{
    public function __construct()
    {
        parent::__construct('/(foo/');
        self::check('/(foo/');
        static::check('/(foo/');
    }
}

final class Unresolved
{
    /**
     * @param class-string<Str> $class
     */
    public function calls(Str $str, string $function, string $method, string $class, \DateTimeImmutable $date): void
    {
        // A callee PHPStan cannot name, or one of PHP's own: nothing to read.
        $function('x', '/(foo/');
        undefined_function('x', '/(foo/');
        $str->{$method}('x', '/(foo/');
        $str->undefined('x', '/(foo/');
        $class::matches('/(foo/', 'x');
        new $class('/(foo/');
        Str::{$method}('/(foo/', 'x');
        new Undefined('/(foo/');
        new class('/(foo/') {
            public function __construct(#[RegexPattern] public string $regex) {}
        };
        $date->format('/(foo/');
        new \ArrayIterator(['/(foo/']);
    }
}
