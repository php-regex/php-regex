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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\PatternEquivalence;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * While "(?xx)" is in force, PCRE2 skips an unescaped space or tab anywhere
 * in a class, not only before its first member (PHP 8.4, PCRE2 10.49):
 * "/(?xx)[a b]/" does not match " ", "/(?xx)[a - c]/" matches "b" and not
 * "-", "/(?xx)[ ^a]/" does not match "a". A line feed, an escaped space and
 * a quoted one stay members. The PHP modifier "xx" is "x": only the inline
 * option reaches a class.
 */
final class ExtendedMoreClassSpaceTest extends TestCase
{
    use PcreGates;

    /**
     * @param array<int|string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideSkippedSpaces')]
    public function test_a_class_under_extended_more_reads_as_if_its_spaces_were_not_written(string $pattern, string $without, array $subjects): void
    {
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on '.json_encode((string) $subject));
            $this->assertSame($matches, preg_match($without, (string) $subject), 'The oracle on '.json_encode((string) $subject));
        }

        $regex = Regex::create(['cache' => null]);

        $this->assertSame(PatternEquivalence::structure($regex->parse($without)), PatternEquivalence::structure($regex->parse($pattern)));
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, subjects: array<int|string, int>}>
     */
    public static function provideSkippedSpaces(): iterable
    {
        yield 'a space between two members' => ['pattern' => '/(?xx)[a b]/', 'without' => '/(?xx)[ab]/', 'subjects' => [' ' => 0, 'b' => 1]];
        yield 'a tab between two members' => ['pattern' => "/(?xx)[a\tb]/", 'without' => '/(?xx)[ab]/', 'subjects' => ["\t" => 0, 'b' => 1]];
        yield 'spaces around the hyphen of a range' => ['pattern' => '/(?xx)[a - c]/', 'without' => '/(?xx)[a-c]/', 'subjects' => ['b' => 1, '-' => 0, ' ' => 0]];
        yield 'a space before the negation' => ['pattern' => '/(?xx)[ ^a]/', 'without' => '/(?xx)[^a]/', 'subjects' => ['a' => 0, 'b' => 1]];
        yield 'a space after the negation' => ['pattern' => '/(?xx)[^ a]/', 'without' => '/(?xx)[^a]/', 'subjects' => ['a' => 0, ' ' => 1]];
        yield 'a space after a POSIX class' => ['pattern' => '/(?xx)[[:alpha:] 1]/', 'without' => '/(?xx)[[:alpha:]1]/', 'subjects' => [' ' => 0, '1' => 1]];
        yield 'a space before a closing bracket' => ['pattern' => '/(?xx)[a ]]/', 'without' => '/(?xx)[a]]/', 'subjects' => ['a]' => 1, ']]' => 0]];
        yield 'in a group that turns xx on' => ['pattern' => '/(?xx:[a b])c/', 'without' => '/(?xx:[ab])c/', 'subjects' => ['ac' => 1, 'bc' => 1, 'cc' => 0]];
    }

    /**
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideKeptSpaces')]
    public function test_a_space_extended_more_does_not_skip_stays_a_member(string $pattern, array $subjects): void
    {
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on '.json_encode((string) $subject));
        }

        $explained = Regex::create(['cache' => null])->explain($pattern);

        $this->assertStringContainsString(' ' === array_key_first($subjects) ? "' ' (space)" : "'\\n'", $explained);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: array<string, int>}>
     */
    public static function provideKeptSpaces(): iterable
    {
        yield 'an escaped space' => ['pattern' => '/(?xx)[a\ b]/', 'subjects' => [' ' => 1]];
        yield 'a quoted space' => ['pattern' => '/(?xx)[\Q \E]/', 'subjects' => [' ' => 1]];
        yield 'under a single x' => ['pattern' => '/(?xx)(?x:[a b])/', 'subjects' => [' ' => 1]];
        yield 'with x turned off' => ['pattern' => '/(?xx)(?-x:[a b])/', 'subjects' => [' ' => 1]];
        yield 'under the modifier xx, which is x' => ['pattern' => '/[a b]/xx', 'subjects' => [' ' => 1]];
    }

    /**
     * "[\d -z]" is "[\d-z]" there from PCRE2 10.45: a range that starts at a
     * class escape, which PCRE2 refuses. Up to 10.44 the space keeps the
     * hyphen from the escape, and the hyphen is a member: "[\d -z]" is
     * "[\d\-z]". The verdict follows the release judged; php-src bundles
     * 10.39 in PHP 8.1, 10.40 in 8.2, 10.42 in 8.3 and 10.44 in 8.4 and 8.5.
     * Checked with pcre2test 10.39, 10.44, 10.45 and 10.48, and PHP 8.1 to
     * 8.6 with their bundled PCRE2 and 8.4 with 10.49.
     */
    #[Test]
    #[DataProvider('provideRangesTheSpacesHid')]
    public function test_a_range_its_spaces_hid_follows_the_release_judged(string $pattern, string $member): void
    {
        foreach (['8.1', '8.2', '8.3', '8.4', '8.5'] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);
            $result = $regex->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s compiles on the PCRE2 PHP %s bundles: %s', $pattern, $phpVersion, (string) $result->error));
            $this->assertSame(PatternEquivalence::structure($regex->parse($member)), PatternEquivalence::structure($regex->parse($pattern)), 'The hyphen is a member on PHP '.$phpVersion);
        }

        foreach (['10.45', '10.48', '10.49'] as $release) {
            $this->assertFalse(Regex::create(['cache' => null, 'pcre_version' => $release])->validate($pattern)->isValid, 'PCRE2 '.$release.' refuses '.$pattern);
        }
    }

    /**
     * Without a target, the running PCRE2 decides, as the engine does; up to
     * 10.44 the pattern matches what the one with the hyphen escaped does.
     */
    #[Test]
    #[DataProvider('provideRangesTheSpacesHid')]
    public function test_a_range_its_spaces_hid_is_judged_as_the_running_engine_judges_it(string $pattern, string $member): void
    {
        $compiles = false !== @preg_match($pattern, '');

        $this->assertSame(!PcreTarget::runtime()->pcreAtLeast('10.45'), $compiles, 'The oracle on PCRE2 '.\PCRE_VERSION);
        $this->assertSame($compiles, Regex::create(['cache' => null])->validate($pattern)->isValid);

        if ($compiles) {
            foreach (['z', '-', '5', 'm', ' ', ']', 'a', "\t"] as $subject) {
                $this->assertSame(preg_match($member, $subject), preg_match($pattern, $subject), 'The oracle on '.json_encode($subject));
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, member: string}>
     */
    public static function provideRangesTheSpacesHid(): iterable
    {
        yield 'a range from a class escape' => ['pattern' => '/(?xx)[\d -z]/', 'member' => '/(?xx)[\d\-z]/'];
        yield 'spaces on both sides of the hyphen' => ['pattern' => '/(?xx)[\d - z]/', 'member' => '/(?xx)[\d\-z]/'];
        yield 'two spaces' => ['pattern' => '/(?xx)[\d  -z]/', 'member' => '/(?xx)[\d\-z]/'];
        yield 'a tab' => ['pattern' => "/(?xx)[\\d\t-z]/", 'member' => '/(?xx)[\d\-z]/'];
        yield 'to a class escape' => ['pattern' => '/(?xx)[\w -\d]/', 'member' => '/(?xx)[\w\-\d]/'];
        yield 'from a POSIX class' => ['pattern' => '/(?xx)[[:alpha:] -z]/', 'member' => '/(?xx)[[:alpha:]\-z]/'];
        yield 'from a property' => ['pattern' => '/(?xx)[\pL -z]/', 'member' => '/(?xx)[\pL\-z]/'];
        yield 'to an escaped bracket' => ['pattern' => '/(?xx)[\d -\]]/', 'member' => '/(?xx)[\d\-\]]/'];
        yield 'in a negated class' => ['pattern' => '/(?xx)[^\d -z]/', 'member' => '/(?xx)[^\d\-z]/'];
    }

    /**
     * The same hyphen right after the escape, or after a single character,
     * is read the same by every release: "[\d- z]" is refused, "[a -z]" is
     * the range a-z and "[\d -]" holds a hyphen.
     */
    #[Test]
    #[DataProvider('provideHyphensEveryReleaseReadsAlike')]
    public function test_a_hyphen_every_release_reads_alike_is_judged_alike(string $pattern, bool $valid): void
    {
        $this->assertSame($valid, false !== @preg_match($pattern, ''), 'The oracle on PCRE2 '.\PCRE_VERSION);

        foreach (['php_version' => ['8.1', '8.4'], 'pcre_version' => ['10.45', '10.49']] as $option => $versions) {
            foreach ($versions as $version) {
                $this->assertSame($valid, Regex::create(['cache' => null, $option => $version])->validate($pattern)->isValid, $pattern.' with '.$option.' '.$version);
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, valid: bool}>
     */
    public static function provideHyphensEveryReleaseReadsAlike(): iterable
    {
        yield 'a hyphen right after a class escape' => ['pattern' => '/(?xx)[\d- z]/', 'valid' => false];
        yield 'a range from a single character' => ['pattern' => '/(?xx)[a -z]/', 'valid' => true];
        yield 'a hyphen before the closing bracket' => ['pattern' => '/(?xx)[\d -]/', 'valid' => true];
        yield 'a range to a class escape' => ['pattern' => '/(?xx)[a -\d]/', 'valid' => false];
        yield 'a hyphen after a range' => ['pattern' => '/(?xx)[a-c -z]/', 'valid' => true];
    }
}
