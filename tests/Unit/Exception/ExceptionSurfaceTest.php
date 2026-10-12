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

namespace PHPRegex\Tests\Unit\Exception;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An exception of the library reads as its results do: through public
 * readonly properties, no getter repeating one.
 */
final class ExceptionSurfaceTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('provideRepeatingGetters')]
    public function test_no_getter_repeats_a_property(string $class, string $getter, string $property): void
    {
        $reflection = new \ReflectionClass($class);

        $this->assertFalse($reflection->hasMethod($getter), $class.'::'.$getter.'()');
        $this->assertTrue($reflection->getProperty($property)->isPublic());
        $this->assertTrue($reflection->getProperty($property)->isReadOnly());
    }

    /**
     * @return iterable<string, array{class: class-string, getter: string, property: string}>
     */
    public static function provideRepeatingGetters(): iterable
    {
        yield 'error code' => ['class' => RegexException::class, 'getter' => 'getErrorCode', 'property' => 'errorCode'];
        yield 'position' => ['class' => ParserException::class, 'getter' => 'getPosition', 'property' => 'position'];
        yield 'snippet' => ['class' => RegexException::class, 'getter' => 'getSnippet', 'property' => 'snippet'];
        yield 'hint' => ['class' => SemanticErrorException::class, 'getter' => 'getHint', 'property' => 'hint'];
    }

    #[Test]
    public function test_a_parse_failure_reads_through_its_properties(): void
    {
        try {
            Regex::create()->parse('/a(/');
            $this->fail('"/a(/" does not parse.');
        } catch (ParserException $exception) {
            $this->assertSame(ErrorCode::GroupUnclosed, $exception->errorCode);
            $this->assertSame(2, $exception->position);
            $this->assertSame($exception->getVisualSnippet(), $exception->snippet);
        }
    }
}
