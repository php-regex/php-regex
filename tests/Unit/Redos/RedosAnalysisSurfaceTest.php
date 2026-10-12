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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\Hotspot;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A verdict reads through its properties, as every result of the library
 * does: one way to read each fact, no getter beside the property.
 */
final class RedosAnalysisSurfaceTest extends TestCase
{
    #[Test]
    public function test_the_confidence_is_always_given(): void
    {
        $confidence = (new \ReflectionProperty(RedosAnalysis::class, 'confidence'))->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $confidence);
        $this->assertFalse($confidence->allowsNull());
        $this->assertSame(RedosConfidence::Low, (new RedosAnalysis(RedosSeverity::Safe, 0))->confidence);
        $this->assertFalse((new \ReflectionClass(RedosAnalysis::class))->hasMethod('confidenceLevel'));
    }

    #[Test]
    public function test_the_culprit_and_the_primary_hotspot_read_without_a_get_prefix(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+$/');

        $this->assertInstanceOf(NodeInterface::class, $analysis->culpritNode);
        $this->assertInstanceOf(Hotspot::class, $analysis->primaryHotspot());
        $this->assertFalse((new \ReflectionClass(RedosAnalysis::class))->hasMethod('getCulpritNode'));
        $this->assertFalse((new \ReflectionClass(RedosAnalysis::class))->hasMethod('getPrimaryHotspot'));
    }
}
