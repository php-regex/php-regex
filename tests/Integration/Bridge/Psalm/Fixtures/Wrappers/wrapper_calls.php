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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Wrappers;

use Composer\Pcre\Preg;
use Composer\Pcre\Regex as R;
use Illuminate\Support\Str;
use Nette\Utils\Strings;
use Spatie\Regex\Regex;
use Webmozart\Assert\Assert;

// A line ending in "// InvalidRegexPattern: <argument>" carries exactly one
// InvalidRegexPattern issue, on that argument; no other line carries one.

function a_pattern_at_the_argument_the_library_takes_it(string $s): void
{
    preg_match('/(/', $s); // InvalidRegexPattern: '/(/'
    Preg::match('/(a/', $s); // InvalidRegexPattern: '/(a/'
    R::isMatch('/(b/', $s); // InvalidRegexPattern: '/(b/'
    \Composer\Pcre\Preg::matchAll('/(c/', $s); // InvalidRegexPattern: '/(c/'
    Strings::match($s, '/(d/'); // InvalidRegexPattern: '/(d/'
    Regex::match('/(e/', $s); // InvalidRegexPattern: '/(e/'
    Str::isMatch('/(f/', $s); // InvalidRegexPattern: '/(f/'
    Assert::regex($s, '/(g/'); // InvalidRegexPattern: '/(g/'
    \PHPUnit\Framework\Assert::assertMatchesRegularExpression('/(h/', $s); // InvalidRegexPattern: '/(h/'
}

/**
 * @param array{string} $rest
 */
function a_pattern_passed_by_name_or_before_a_spread(string $s, array $rest): void
{
    Preg::match(subject: $s, pattern: '/(i/'); // InvalidRegexPattern: '/(i/'
    Strings::match(pattern: '/(j/', subject: $s); // InvalidRegexPattern: '/(j/'
    Preg::match('/(aj/', ...$rest); // InvalidRegexPattern: '/(aj/'
}

function a_pattern_held_by_a_variable_or_a_constant(string $s): void
{
    $pattern = '/(k/';
    Preg::match($pattern, $s); // InvalidRegexPattern: $pattern
    Preg::match(Patterns::OPEN, $s); // InvalidRegexPattern: Patterns::OPEN
}

function the_patterns_of_a_list_and_the_keys_of_a_map(string $s): void
{
    Preg::replace(['/x/', '/(l/'], '', $s); // InvalidRegexPattern: '/(l/'
    Preg::replaceCallbackArray([
        '/y/' => static fn (array $m): string => '',
        '/(m/' => static fn (array $m): string => '', // InvalidRegexPattern: '/(m/'
    ], $s);
    // Without a callable, nette/utils reads the keys of a map whose first
    // key is a string, and the values of a list.
    Strings::replace($s, ['/(n/' => 'x']); // InvalidRegexPattern: '/(n/'
    Strings::replace($s, ['/(o/' => 'x'], 'y'); // InvalidRegexPattern: '/(o/'
    Strings::replace($s, ['/(p/']); // InvalidRegexPattern: '/(p/'
    // A key PHP stores as an integer is no string, however it is written.
    Strings::replace($s, [3 => '/(aa/']); // InvalidRegexPattern: '/(aa/'
    Strings::replace($s, ['7' => '/(ab/']); // InvalidRegexPattern: '/(ab/'
    // With a callable, the values, whatever the keys.
    Strings::replace($s, ['/(q/'], static fn (array $m): string => ''); // InvalidRegexPattern: '/(q/'
    Strings::replace($s, ['x' => '/(r/'], static fn (array $m): string => ''); // InvalidRegexPattern: '/(r/'
}

function a_pattern_nette_reads_under_utf8(string $s): void
{
    // "\x{100}" is past what a pattern without u may name.
    Strings::match($s, '/\x{100}(/', utf8: true); // InvalidRegexPattern: '/\x{100}(/'
    Strings::match($s, '/\x{100}/'); // InvalidRegexPattern: '/\x{100}/'
    Strings::match($s, '/\x{100}/', utf8: false); // InvalidRegexPattern: '/\x{100}/'
    Strings::match($s, '/\x{100}/', utf8: true);
    Strings::match($s, '/\x{100}/', false, 0, false, true);
    Strings::replace($s, ['/\x{100}/' => 'x'], utf8: true);
    Strings::replace($s, ['/\x{100}/'], static fn (array $m): string => '', utf8: true);
}

final class Patterns
{
    public const OPEN = '/(s/';
}

final class Unrelated
{
    public static function match(string $pattern, string $subject): bool
    {
        return '' !== $pattern.$subject;
    }
}

/**
 * @param string|callable(array<array-key, string|null>): string $replacement
 * @param array{string, string}                                  $both
 * @param array<string, string>                                  $map
 * @param string|\Closure(array<array-key, string|null>): string $either
 */
function calls_the_plugin_does_not_read(string $s, bool $flag, string|callable $replacement, array $both, array $map, string|\Closure $either): void
{
    // No class of a library, and a library's method that takes no pattern.
    Unrelated::match('/(u/', $s);
    // The pattern stands second in nette/utils: the first argument is the subject.
    Strings::match('/(v/', $s);
    // Whether nette/utils appends u is not known, nor what the pattern then is.
    Strings::match($s, '/\x{100}/', utf8: $flag);
    // A replacement that may be a callable, or a closure: the keys or the
    // values.
    Strings::replace($s, ['/(w/' => '/(x/'], $replacement);
    Strings::replace($s, ['/(ah/' => '/(ai/'], $either);
    // A first key the code does not spell, and a spread before it: the
    // keys or the values.
    Strings::replace($s, [$s => '/(ac/', '/(ad/' => '/(ae/']);
    Strings::replace($s, [...$map, '/(af/' => '/(ag/']);
    // Arguments a spread holds, and a class named by a value.
    Preg::match(...$both);
    $class = Preg::class;
    $class::match('/(y/', $s);
    // A callable taken of the method runs nothing.
    $match = Preg::match(...);
    $match('/z/', $s);
    // Valid patterns.
    Preg::match('/(a)(b)?/', $s);
    Strings::replace($s, ['/a/' => 'b', '/c/' => 'd']);
}
