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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Toolkit\AnalysisReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A result object is read through its public readonly properties: no
 * accessor repeats one of them under a second name.
 */
final class ResultPropertiesTest extends TestCase
{
    /**
     * @param class-string $class
     * @param list<string> $methods
     */
    #[Test]
    #[DataProvider('provideResults')]
    public function test_a_result_has_no_accessor_beside_its_properties(string $class, array $methods): void
    {
        $public = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }
        sort($public);

        $this->assertSame($methods, $public);
    }

    /**
     * @return iterable<string, array{class: class-string, methods: list<string>}>
     */
    public static function provideResults(): iterable
    {
        yield 'AnalysisReport' => ['class' => AnalysisReport::class, 'methods' => ['__construct']];
        yield 'ValidationResult' => ['class' => ValidationResult::class, 'methods' => ['__construct', 'jsonSerialize']];
    }
}
