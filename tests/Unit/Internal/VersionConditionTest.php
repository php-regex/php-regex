<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Internal;

use PhpRegex\Parser\Internal\VersionCondition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the "VERSION>=10.4" a conditional can branch on.
 */
final class VersionConditionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideConditions')]
    public function test_a_version_condition_is_read(string $text, string $operator, string $version): void
    {
        $condition = VersionCondition::read($text);

        $this->assertInstanceOf(VersionCondition::class, $condition);
        $this->assertSame($operator, $condition->operator);
        $this->assertSame($version, $condition->version);
    }

    /**
     * @return iterable<string, array{text: string, operator: string, version: string}>
     */
    public static function provideConditions(): iterable
    {
        yield 'at least' => ['text' => 'VERSION>=10.4', 'operator' => '>=', 'version' => '10.4'];
        yield 'at most' => ['text' => 'VERSION<=10', 'operator' => '<=', 'version' => '10'];
        yield 'exactly' => ['text' => 'VERSION==10.40.1', 'operator' => '==', 'version' => '10.40.1'];
        yield 'anything but' => ['text' => 'VERSION!=10.4', 'operator' => '!=', 'version' => '10.4'];
        yield 'strictly above' => ['text' => 'VERSION>10.4', 'operator' => '>', 'version' => '10.4'];
        yield 'strictly below' => ['text' => 'VERSION<10.4', 'operator' => '<', 'version' => '10.4'];

        // The spaces a pattern may pad the condition with.
        yield 'padded with spaces' => ['text' => '  VERSION >= 10.4 ', 'operator' => '>=', 'version' => '10.4'];
    }

    #[Test]
    #[DataProvider('provideNonConditions')]
    public function test_what_is_not_a_version_condition_is_refused(string $text): void
    {
        $this->assertNotInstanceOf(VersionCondition::class, VersionCondition::read($text));
    }

    /**
     * @return iterable<string, array{text: string}>
     */
    public static function provideNonConditions(): iterable
    {
        yield 'a group name' => ['text' => 'name'];
        yield 'the word alone' => ['text' => 'VERSION'];
        yield 'no comparison' => ['text' => 'VERSION 10.4'];
        yield 'no version' => ['text' => 'VERSION>='];
        yield 'not a number' => ['text' => 'VERSION>=ten'];
        yield 'an empty part' => ['text' => 'VERSION>=10..4'];
        yield 'lowercase' => ['text' => 'version>=10.4'];
    }
}
