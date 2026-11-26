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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\InlineFlagsRule;
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?xx)" is an option of its own: a class drops its spaces and tabs under
 * it, which "x" alone leaves in. A group that sets "x" alone takes it off
 * again, "(?-x)" and "(?^)" take both off, and "(?xxix)" keeps it, as
 * PCRE2 reads the letters of a group together. PHP's "x" modifier is "x"
 * however many times it is written: "/[a b]/xx" takes the space.
 *
 * Engine: PHP 8.4.28 with PCRE2 10.49, PHP 8.2.36 with 10.40, PHP 8.6.0
 * with 10.44, the JIT on and off: "/^(?xx)[a b]$/" matches no space,
 * "/^(?x)[a b]$/", "/^[a b]$/xx", "/^(?xx)(?x)[a b]$/",
 * "/^(?xx)(?-x)[a b]$/" and "/^(?xx)(?^)[a b]$/" match one.
 */
final class InlineExtendedMoreTest extends TestCase
{
    /**
     * @param string $option    the options the branches are read under, spelled before them
     * @param string $modifiers the pattern's modifiers
     */
    #[Test]
    #[DataProvider('provideOverlapRows')]
    public function test_a_class_drops_its_spaces_where_xx_is_in_force(string $option, string $modifiers, bool $reported): void
    {
        // A scoped option, "(?xx:", closes after the branches.
        $close = str_ends_with($option, ':') ? ')' : '';
        $pattern = '/'.$option.'(?:[a b]|\x20|\xE9)+'.$close.'/'.$modifiers;

        // Oracle: the class takes a space, which the second branch takes,
        // where the branches overlap.
        $probe = '/^'.$option.'(?:[a b])'.$close.'$/'.$modifiers;
        $taken = [preg_match($probe, ' '), preg_match('/(*NO_JIT)'.substr($probe, 1), ' ')];
        $this->assertSame($reported ? [1, 1] : [0, 0], $taken, 'Oracle: '.$probe.' on a space.');

        $this->assertSame($reported, \in_array(OverlappingAlternationRule::ID_CHARSET, self::ids($pattern), true), $pattern);
    }

    /**
     * @return iterable<string, array{option: string, modifiers: string, reported: bool}>
     */
    public static function provideOverlapRows(): iterable
    {
        yield 'no option' => ['option' => '', 'modifiers' => '', 'reported' => true];
        yield 'x set inline' => ['option' => '(?x)', 'modifiers' => '', 'reported' => true];
        yield 'xx set inline' => ['option' => '(?xx)', 'modifiers' => '', 'reported' => false];
        yield 'xx scoped' => ['option' => '(?xx:', 'modifiers' => '', 'reported' => false];
        yield 'the x modifier' => ['option' => '', 'modifiers' => 'x', 'reported' => true];
        yield 'the x modifier written twice' => ['option' => '', 'modifiers' => 'xx', 'reported' => true];
        yield 'xx under the x modifier' => ['option' => '(?xx)', 'modifiers' => 'x', 'reported' => false];
        yield 'xx, then x alone' => ['option' => '(?xx)(?x)', 'modifiers' => '', 'reported' => true];
        yield 'xx, then x with another letter' => ['option' => '(?xx)(?ix)', 'modifiers' => '', 'reported' => true];
        yield 'xx, then x unset' => ['option' => '(?xx)(?-x)', 'modifiers' => '', 'reported' => true];
        yield 'xx, then every option reset' => ['option' => '(?xx)(?^)', 'modifiers' => '', 'reported' => true];
        yield 'xx, then another option' => ['option' => '(?xx)(?i)', 'modifiers' => '', 'reported' => false];
        yield 'x, then xx' => ['option' => '(?x)(?xx)', 'modifiers' => '', 'reported' => false];
        yield 'xx and x in one group' => ['option' => '(?xxix)', 'modifiers' => '', 'reported' => false];
        yield 'two single x in one group' => ['option' => '(?xix)', 'modifiers' => '', 'reported' => true];
    }

    /**
     * An option setting is redundant where the pattern matches the same
     * without it: "(?x)" after "(?xx)" takes xx off, "(?xx)" under the x
     * modifier turns it on.
     *
     * @param list<string> $flags the redundant options the rule names, one finding each
     */
    #[Test]
    #[DataProvider('provideRedundantRows')]
    public function test_an_option_setting_is_redundant_only_where_it_changes_nothing(string $pattern, string $without, array $flags): void
    {
        // Oracle: the pattern and the pattern without the setting match the
        // same subjects, where the setting is redundant.
        $differ = [];
        foreach (['a', ' ', 'b', 'x'] as $subject) {
            foreach (['', '(*NO_JIT)'] as $path) {
                if (preg_match('/'.$path.substr($pattern, 1), $subject) !== preg_match('/'.$path.substr($without, 1), $subject)) {
                    $differ[] = $path.json_encode($subject);
                }
            }
        }
        $this->assertSame([] !== $flags, [] === $differ, \sprintf('Oracle: %s and %s differ on %s.', $pattern, $without, implode(', ', $differ)));

        $found = [];
        foreach (self::issues($pattern) as [$id, $message]) {
            if (InlineFlagsRule::ID_REDUNDANT === $id && 1 === preg_match("/^Inline flag '([^']+)' is redundant/", $message, $match)) {
                $found[] = $match[1];
            }
        }
        $this->assertSame($flags, $found, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, flags: list<string>}>
     */
    public static function provideRedundantRows(): iterable
    {
        yield 'x after xx' => ['pattern' => '/^(?xx)(?x)[a b]$/', 'without' => '/^(?xx)[a b]$/', 'flags' => []];
        yield 'xx after xx' => ['pattern' => '/^(?xx)(?xx)[a b]$/', 'without' => '/^(?xx)[a b]$/', 'flags' => ['xx']];
        yield 'xx under the x modifier' => ['pattern' => '/^(?xx)[a b]$/x', 'without' => '/^[a b]$/x', 'flags' => []];
        yield 'xx under the x modifier written twice' => ['pattern' => '/^(?xx)[a b]$/xx', 'without' => '/^[a b]$/xx', 'flags' => []];
        yield 'x under the x modifier' => ['pattern' => '/^(?x)[a b]$/x', 'without' => '/^[a b]$/x', 'flags' => ['x']];
        yield 'xx after x' => ['pattern' => '/^(?x)(?xx)[a b]$/', 'without' => '/^(?x)[a b]$/', 'flags' => []];
        yield 'x after x' => ['pattern' => '/^(?x)(?x)[a b]$/', 'without' => '/^(?x)[a b]$/', 'flags' => ['x']];
        yield 'i after xx and i' => ['pattern' => '/^(?xxi)(?i)[a b]$/', 'without' => '/^(?xxi)[a b]$/', 'flags' => ['i']];
    }

    /**
     * No modifier sets "xx": a redundant "(?xx)" was set by an earlier
     * option setting, under "/xx" too.
     */
    #[Test]
    public function test_a_redundant_xx_was_set_by_an_earlier_option_setting(): void
    {
        // Oracle: "/xx" leaves the class its space, the first "(?xx)" takes
        // it off, the second changes nothing.
        $this->assertSame([1, 0, 0], [preg_match('/^[a b]$/xx', ' '), preg_match('/^(?xx)a[a b]$/xx', 'a '), preg_match('/^(?xx)a(?xx)[a b]$/xx', 'a ')]);

        $messages = [];
        foreach (self::issues('/^(?xx)a(?xx)[a b]$/xx') as [$id, $message]) {
            if (InlineFlagsRule::ID_REDUNDANT === $id) {
                $messages[] = $message;
            }
        }

        $this->assertSame(["Inline flag 'xx' is redundant; it is already set by an earlier inline flag group."], $messages);
    }

    /**
     * The flags in force after an option setting, "xx" for the option of
     * its own.
     */
    #[Test]
    #[DataProvider('provideAppliedFlags')]
    public function test_an_option_setting_keeps_xx_apart_from_x(string $base, string $inline, string $flags): void
    {
        $this->assertSame($flags, NodePredicates::applyInlineFlags($base, $inline));
    }

    /**
     * @return iterable<string, array{base: string, inline: string, flags: string}>
     */
    public static function provideAppliedFlags(): iterable
    {
        yield 'xx set' => ['base' => 'i', 'inline' => 'xx', 'flags' => 'ixx'];
        yield 'xx kept by another letter' => ['base' => 'xx', 'inline' => 'i', 'flags' => 'xxi'];
        yield 'xx taken off by x alone' => ['base' => 'xx', 'inline' => 'x', 'flags' => 'x'];
        yield 'xx taken off by x beside another letter' => ['base' => 'xx', 'inline' => 'ix', 'flags' => 'xi'];
        yield 'xx kept by xx beside x' => ['base' => '', 'inline' => 'xxix', 'flags' => 'xxi'];
        yield 'xx taken off by x unset' => ['base' => 'ixx', 'inline' => '-x', 'flags' => 'i'];
        yield 'xx taken off by a reset' => ['base' => 'ixx', 'inline' => '^s', 'flags' => 's'];
        yield 'x set over x' => ['base' => 'x', 'inline' => 'x', 'flags' => 'x'];
    }

    /**
     * @return list<string>
     */
    private static function ids(string $pattern): array
    {
        return array_column(self::issues($pattern), 0);
    }

    /**
     * @return list<array{string, string}>
     */
    private static function issues(string $pattern): array
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => 'C']);
        $linter = new PatternLinter($regex->target());
        $regex->parse($pattern)->accept($linter);

        return array_map(static fn ($issue): array => [$issue->id, $issue->message], $linter->getIssues());
    }
}
