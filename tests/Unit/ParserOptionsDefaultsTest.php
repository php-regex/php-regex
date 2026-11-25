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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The default limits belong to the options; the parser keeps its names for
 * them, the same values.
 */
final class ParserOptionsDefaultsTest extends TestCase
{
    #[Test]
    public function test_the_options_hold_the_default_limits(): void
    {
        $options = ParserOptions::fromArray([]);

        $this->assertSame(ParserOptions::DEFAULT_MAX_PATTERN_LENGTH, $options->maxPatternLength);
        $this->assertSame(ParserOptions::DEFAULT_MAX_LOOKBEHIND_LENGTH, $options->maxLookbehindLength);
        $this->assertSame(ParserOptions::DEFAULT_MAX_RECURSION_DEPTH, $options->maxRecursionDepth);
    }

    /**
     * Options built in code name only what they change: every argument
     * has the default fromArray() gives the key left out.
     */
    #[Test]
    public function test_the_constructor_takes_the_defaults_of_every_argument(): void
    {
        $options = new ParserOptions();
        $target = new PcreTarget(80200, '10.40', 'C');

        $this->assertSame(ParserOptions::DEFAULT_MAX_PATTERN_LENGTH, $options->maxPatternLength);
        $this->assertSame(ParserOptions::DEFAULT_MAX_LOOKBEHIND_LENGTH, $options->maxLookbehindLength);
        $this->assertInstanceOf(ArrayCache::class, $options->cache);
        $this->assertNotSame($options->cache, (new ParserOptions())->cache);
        $this->assertFalse($options->runtimePcreValidation);
        $this->assertSame(ParserOptions::DEFAULT_MAX_RECURSION_DEPTH, $options->maxRecursionDepth);
        $this->assertEquals(PcreTarget::runtime(), $options->target);
        $this->assertSame($target, (new ParserOptions(target: $target))->target);
        $this->assertEquals(ParserOptions::fromArray([]), $options);
    }

    #[Test]
    public function test_the_parser_names_the_same_defaults(): void
    {
        foreach (['DEFAULT_MAX_PATTERN_LENGTH', 'DEFAULT_MAX_LOOKBEHIND_LENGTH', 'DEFAULT_MAX_RECURSION_DEPTH'] as $name) {
            $this->assertSame(
                (new \ReflectionClassConstant(ParserOptions::class, $name))->getValue(),
                (new \ReflectionClassConstant(RegexParser::class, $name))->getValue(),
                $name,
            );
        }
    }
}
