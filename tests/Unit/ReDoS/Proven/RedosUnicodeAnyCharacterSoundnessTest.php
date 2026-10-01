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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A character set read larger than it is can hide the continuation that
 * rejects: if "." under /u is read as every character, \n included, every
 * continuation is accepted and nothing seems to backtrack. The engine says
 * otherwise, so such a pattern is never "safe (proven)".
 *
 * Engine (PHP 8.4.26 / PCRE2 10.49, pcre.backtrack_limit 1000000, JIT on
 * and off): each row fails preg_match() at 19 pumps of "a" followed by its
 * suffix, with "Backtrack limit exhausted".
 */
final class RedosUnicodeAnyCharacterSoundnessTest extends TestCase
{
    #[Test]
    #[DataProvider('provideVulnerablePatterns')]
    public function test_set_excluding_a_character_is_never_proven_safe(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse($analysis->isProvenSafe(), $pattern.': '.json_encode($analysis->abstractions));
        $this->assertNotSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity, $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideVulnerablePatterns(): iterable
    {
        foreach (self::provideEngineFacts() as $name => [$pattern, , $fails]) {
            if ($fails) {
                yield $name => [$pattern];
            }
        }
    }

    /**
     * Under /s the dot takes every character: no continuation rejects, the
     * verdict stays linear (a…a! never fails up to 64 bytes).
     */
    #[Test]
    public function test_dot_under_s_and_u_stays_proven_safe(): void
    {
        $this->assertTrue((new RedosAnalyzer())->analyze('/(.+)+$/su')->isProvenSafe());
    }

    #[Test]
    #[DataProvider('provideEngineFacts')]
    public function test_engine_fact(string $pattern, string $suffix, bool $fails): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $failing = false;
            for ($n = 1; !$failing && $n + \strlen($suffix) <= 64; $n++) {
                $failing = false === @preg_match($pattern, str_repeat('a', $n).$suffix);
            }
        } finally {
            if (false !== $limit) {
                ini_set('pcre.backtrack_limit', $limit);
            }
        }

        $this->assertSame($fails, $failing, $pattern);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideEngineFacts(): iterable
    {
        yield 'dot under u, a…a\n!' => ['/(.+)+$/u', "\n!", true];
        yield 'dot under u before the absolute end, a…a\n' => ['/(.+)+\z/u', "\n", true];
        yield 'not a newline under u, a…a\n!' => ['/(\N+)+$/u', "\n!", true];
        yield 'non-space under u, a…a followed by a space' => ['/(\S+)+$/u', ' ', true];
        yield 'non-horizontal-space under u, a…a\t' => ['/(\H+)+$/u', "\t", true];
        yield 'non-vertical-space under u, a…a\n!' => ['/(\V+)+$/u', "\n!", true];
        yield 'dot under s and u, a…a!' => ['/(.+)+$/su', '!', false];
    }
}
