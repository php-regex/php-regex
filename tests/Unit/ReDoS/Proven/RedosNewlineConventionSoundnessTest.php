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
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The dot stops at the newline of the pattern's convention, not always at
 * "\n": under (*CR), (*CRLF) or (*NUL) a lone "\n" is an ordinary character
 * the dot takes, so in (?:.*\n)+ both ".*" and "\n" can consume it and the
 * loop backtracks exponentially, as it does under /s.
 *
 * Engine (PHP 8.4.26 / PCRE2 10.49, pcre.jit 0, (*NO_START_OPT), subject
 * "a" followed by n "\n"): the least pcre.backtrack_limit that lets the
 * call finish is 513 at n = 8 and 131073 at n = 16 for every convention
 * that is not LF, and for /s; 11 and 19 under LF (the default, (*LF),
 * (*ANYCRLF) and (*ANY) alike).
 */
final class RedosNewlineConventionSoundnessTest extends TestCase
{
    private string|false $backtrackLimit = false;

    private string|false $jit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        $this->jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
        if (false !== $this->jit) {
            ini_set('pcre.jit', $this->jit);
        }
    }

    #[Test]
    #[DataProvider('provideNewlineConventions')]
    public function test_redos_newline_convention_decides_whether_the_dot_crosses_a_line_feed(string $pattern, bool $vulnerable): void
    {
        $engine = '/(*NO_START_OPT)'.substr($pattern, 1);
        $growth = self::steps($engine, 16) / self::steps($engine, 8);
        $this->assertSame($vulnerable, $growth > 3, \sprintf('%s: steps grow x%.1f when n doubles', $engine, $growth));

        $analysis = (new RedosAnalyzer())->analyze($pattern);

        if (!$vulnerable) {
            $this->assertSame(RedosSeverity::Safe, $analysis->severity, $pattern);

            return;
        }

        $this->assertFalse($analysis->isSafe(), $pattern.' is reported '.$analysis->headline());
        // The level /(?:.*\n)+x/s gets, proven or from the heuristics alone.
        $this->assertGreaterThanOrEqual(
            RedosSeverity::Critical->rank(),
            $analysis->severity->rank(),
            $pattern.' is reported '.$analysis->severity->value,
        );
    }

    /**
     * @return iterable<string, array{pattern: string, vulnerable: bool}>
     */
    public static function provideNewlineConventions(): iterable
    {
        yield 'carriage return convention' => ['pattern' => '/(*CR)(?:.*\n)+x/', 'vulnerable' => true];
        yield 'CRLF convention' => ['pattern' => '/(*CRLF)(?:.*\n)+x/', 'vulnerable' => true];
        yield 'NUL convention' => ['pattern' => '/(*NUL)(?:.*\n)+x/', 'vulnerable' => true];
        yield 'dotall, the reference level' => ['pattern' => '/(?:.*\n)+x/s', 'vulnerable' => true];
        yield 'explicit LF convention' => ['pattern' => '/(*LF)(?:.*\n)+x/', 'vulnerable' => false];
        yield 'default LF convention' => ['pattern' => '/(?:.*\n)+x/', 'vulnerable' => false];
    }

    /**
     * The least backtrack limit under which one call on "a" followed by
     * $n line feeds finishes.
     */
    private static function steps(string $pattern, int $n): int
    {
        $subject = 'a'.str_repeat("\n", $n);
        $limit = ini_get('pcre.backtrack_limit');
        $low = 1;
        $high = 10_000_000;

        try {
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($pattern, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }
        } finally {
            // The analyzer runs the engine too: never leave it a probe's limit.
            if (false !== $limit) {
                ini_set('pcre.backtrack_limit', $limit);
            }
        }

        return $low;
    }
}
