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

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector;

use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The witness pairs of the "{1}+" flip, each pattern with what pcre2test
 * built from the 10.32 and 10.33 tarballs finds in its subject: the first
 * match, or null. The running engine, 10.34 or later, is the other side.
 * The tree is the same on both sides, so comparing trees never sees the
 * flip: these pairs do.
 */
final class PossessiveRepeatOnceWitnessTest extends TestCase
{
    /**
     * On 10.33 the possessive repeat is ignored, so the pattern matches what
     * its plain "{1}" spelling matches; from 10.34 it is atomic, and the
     * rewrite the rule makes keeps what 10.33 matched.
     */
    #[Test]
    #[DataProvider('provideFlips')]
    public function test_the_repeat_flips_and_the_rewrite_keeps_what_the_old_release_matched(string $pattern, string $rewritten, string $subject, ?string $oldMatch): void
    {
        $this->assertTrue(PcreTarget::runtime()->supports(PcreFeature::PossessiveRepeatOnceIsAtomic), 'The running engine is the later side.');

        $this->assertNotSame($oldMatch, self::firstMatch($pattern, $subject), 'The engine flips: '.$pattern);
        $this->assertSame($oldMatch, self::firstMatch($rewritten, $subject), 'The rewrite matches what PCRE2 10.33 matched with '.$pattern);
    }

    /**
     * The other way, an atomic group keeps what 10.34 and later match on
     * every release: pcre2test 10.32 and 10.33 find nothing with
     * "/(?>(a|ab))c/" in "abc", as the running engine finds nothing with
     * "/(a|ab){1}+c/".
     */
    #[Test]
    public function test_an_atomic_group_reads_the_later_meaning_on_every_release(): void
    {
        $this->assertNull(self::firstMatch('/(a|ab){1}+c/', 'abc'));
        $this->assertNull(self::firstMatch('/(?>(a|ab))c/', 'abc'));
    }

    /**
     * @return iterable<string, array{pattern: string, rewritten: string, subject: string, oldMatch: string|null}>
     */
    public static function provideFlips(): iterable
    {
        yield 'capturing group' => ['pattern' => '/(a|ab){1}+c/', 'rewritten' => '/(a|ab){1}c/', 'subject' => 'abc', 'oldMatch' => 'abc'];
        yield 'group of a repeat' => ['pattern' => '/(a+){1}+a/', 'rewritten' => '/(a+){1}a/', 'subject' => 'aaa', 'oldMatch' => 'aaa'];
        yield 'non-capturing group, bounds spelled twice' => ['pattern' => '/(?:a|ab){1,1}+c/', 'rewritten' => '/(?:a|ab){1,1}c/', 'subject' => 'abc', 'oldMatch' => 'abc'];
        yield 'named group, a padded count' => ['pattern' => '/(?<n>a|ab){01}+c/', 'rewritten' => '/(?<n>a|ab){01}c/', 'subject' => 'abc', 'oldMatch' => 'abc'];
        yield 'branch reset group' => ['pattern' => '/(?|(a)|(ab)){1}+c/', 'rewritten' => '/(?|(a)|(ab)){1}c/', 'subject' => 'abc', 'oldMatch' => 'abc'];
        yield 'option group' => ['pattern' => '/(?i:a|ab){1}+c/', 'rewritten' => '/(?i:a|ab){1}c/', 'subject' => 'abc', 'oldMatch' => 'abc'];
        yield 'subroutine call' => ['pattern' => '/(a|ab)(?1){1}+c/', 'rewritten' => '/(a|ab)(?1){1}c/', 'subject' => 'aabc', 'oldMatch' => 'aabc'];
    }

    private static function firstMatch(string $pattern, string $subject): ?string
    {
        return 1 === preg_match($pattern, $subject, $match) ? $match[0] : null;
    }
}
