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

namespace PHPRegex\Tests\Integration\Conformance;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\Participation;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The conformance suite: every claim the capture shape makes about a
 * group — participation, values, digits, truthiness, length — holds for
 * what the real engine writes into $matches, on every subject and every
 * flag the claim depends on. The fixture is public API: a consumer can
 * run the same file against its own reading of the shape.
 */
final class ShapeConformanceTest extends TestCase
{
    private const FLAG_SETS = [
        0,
        \PREG_UNMATCHED_AS_NULL,
    ];

    #[Test]
    #[DataProvider('provideCases')]
    public function test_every_shape_claim_holds_for_what_the_engine_writes(string $pattern, array $subjects): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(
            RegexParser::create()->parse($pattern),
        );

        foreach ($subjects as $subject) {
            foreach (self::FLAG_SETS as $flags) {
                $matched = @\preg_match($pattern, $subject, $matches, $flags);
                if (1 !== $matched) {
                    continue; // a subject the pattern does not match says nothing
                }

                // only under PREG_UNMATCHED_AS_NULL does the engine keep
                // every numbered group in the array; without it the trailing
                // unset ones are omitted and the count says nothing
                if (($flags & \PREG_UNMATCHED_AS_NULL) !== 0) {
                    $engineCount = 0;
                    foreach ($matches as $key => $value) {
                        if (\is_int($key) && $key > $engineCount) {
                            $engineCount = $key;
                        }
                    }

                    $this->assertCount(
                        $engineCount,
                        $shape->groups,
                        \sprintf('%s on %s (flags %d): the engine numbers %d groups, the shape %d', $pattern, $subject, $flags, $engineCount, \count($shape->groups)),
                    );
                }

                foreach ($shape->groups as $group) {
                    $written = $matches[$group->number] ?? null;
                    $label = \sprintf('%s group %d on %s (flags %d)', $pattern, $group->number, $subject, $flags);

                    if (null === $written || (($flags & \PREG_UNMATCHED_AS_NULL) === 0 && '' === $written && !\array_key_exists($group->number, $matches))) {
                        $this->assertNotSame(
                            Participation::Always,
                            $group->participation,
                            $label.': the engine left it unset, the shape says it never is',
                        );

                        continue;
                    }

                    // without PREG_UNMATCHED_AS_NULL an unset group reads as
                    // the empty string, indistinguishable from a matched
                    // empty: a group the shape says may be unset carries no
                    // fact to check when it reads empty, and a Never group
                    // never holds a value at all
                    $holdsNoValue = Participation::Never === $group->participation
                        || (($flags & \PREG_UNMATCHED_AS_NULL) === 0 && '' === $written && Participation::MayBeUnset === $group->participation);

                    if ($holdsNoValue) {
                        continue;
                    }

                    if (null !== $group->values) {
                        $this->assertContains($written, $group->values, $label.': the engine wrote a value outside the enumerated set');
                    }

                    if ($group->digitsOnly) {
                        $this->assertTrue(\ctype_digit($written) && '' !== $written, $label.': the engine wrote a non-digit or empty value against digitsOnly');
                    }

                    if ($group->nonFalsy) {
                        $this->assertNotSame('', $written, $label.': the engine wrote the empty string against nonFalsy');
                        $this->assertNotSame('0', $written, $label.': the engine wrote "0" against nonFalsy');
                    }

                    $this->assertGreaterThanOrEqual($group->minLength, \strlen($written), $label.': the engine wrote a shorter value than the minimum');
                }
            }
        }

        $this->addToAssertionCount(1); // every matching subject/flag pair held
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideCases(): iterable
    {
        $fixture = \json_decode(
            (string) \file_get_contents(__DIR__.'/../../Fixtures/Conformance/engine-claims.json'),
            true,
        );

        foreach ($fixture['cases'] as $i => $case) {
            yield \sprintf('#%d %s', $i, $case['pattern']) => [
                'pattern' => $case['pattern'],
                'subjects' => $case['subjects'],
            ];
        }
    }
}
