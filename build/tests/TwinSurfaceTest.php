<?php

declare(strict_types=1);

namespace PHPRegex\Build\Tests;

use PHPRegex\Build\Verify\ApiSurface;
use PHPUnit\Framework\TestCase;

/**
 * A consumer reaches in the twin what it reaches in the original: the same
 * classes, the same methods with the same parameters, the same public
 * properties and constants, the same enum cases with the same values.
 */
final class TwinSurfaceTest extends TestCase
{
    public function test_the_twin_offers_what_the_original_offers(): void
    {
        $original = Reference::api();
        $twin = ApiSurface::of(\dirname(__DIR__).'/php74/src');

        $this->assertSame(array_keys($original), array_keys($twin), 'The twin has other classes.');
        foreach ($original as $class => $surface) {
            $this->assertSame($surface, $twin[$class], $class.' differs.');
        }
    }
}
