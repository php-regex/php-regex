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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Unit\Parser\CaptureShapeAnalyzerTest;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

/**
 * The match shape is a PHPStan type: PHPStan reads it, and the type it reads
 * holds every $matches the engine writes, every key included. The rows are
 * the analyzer test's engine rows (a group named MARK beside a mark verb, a
 * name in one branch of a branch reset, names shared under (?J), /n).
 */
final class CaptureShapePhpStanTypeTest extends PHPStanTestCase
{
    private const FLAG_SETS = [0, \PREG_UNMATCHED_AS_NULL, \PREG_OFFSET_CAPTURE, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProviderExternal(CaptureShapeAnalyzerTest::class, 'provideEngineRows')]
    public function test_the_shape_phpstan_reads_holds_what_the_engine_writes(string $pattern, array $subjects): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);

        foreach (self::FLAG_SETS as $flags) {
            $written = $shape->matchShape($flags);
            $type = $resolver->resolve($written);
            $this->assertInstanceOf(ConstantArrayType::class, $type, \sprintf('PHPStan reads "%s" as %s.', $written, $type->describe(VerbosityLevel::precise())));

            foreach ($subjects as $subject) {
                $matches = [];
                preg_match($pattern, $subject, $matches, $flags);
                $actual = ConstantTypeHelper::getTypeFromValue($matches);

                $this->assertTrue(
                    $type->isSuperTypeOf($actual)->yes(),
                    \sprintf('%s on "%s" with flags %d wrote %s, outside %s.', $pattern, $subject, $flags, $actual->describe(VerbosityLevel::precise()), $written),
                );

                // An array shape takes extra keys as a subtype: each key the
                // engine writes must be one PHPStan knows, or reading it is
                // reported as an offset that does not exist.
                foreach (array_keys($matches) as $key) {
                    $this->assertFalse(
                        $type->hasOffsetValueType(ConstantTypeHelper::getTypeFromValue($key))->no(),
                        \sprintf('%s on "%s" with flags %d wrote key "%s", missing from %s.', $pattern, $subject, $flags, $key, $written),
                    );
                }
            }
        }
    }
}
