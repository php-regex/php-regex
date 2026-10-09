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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PHPRegex\PHPStan\PatternChecker;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternCheckerTest extends TestCase
{
    #[Test]
    public function test_the_verdict_of_an_incomplete_analysis_says_it_was_not_analyzed(): void
    {
        // The oracle: PCRE2 refuses a reversed range; the analysis stops at
        // the library's validation, unfinished, of unknown severity.
        $pattern = '/[z-a]+$/';
        $this->assertFalse(self::runningEngineCompiles($pattern));
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low);
        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);

        $verdict = (new \ReflectionMethod(PatternChecker::class, 'redosVerdict'))->invoke(null, $analysis);

        $this->assertSame('unknown, not analyzed', $verdict);
    }

    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }
}
