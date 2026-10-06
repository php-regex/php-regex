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

namespace PHPRegex\Tests\Integration\Bridge\Rector;

use PHPRegex\Rector\PregMatchToStringComparisonRector;
use PHPRegex\Rector\PregReplaceToStrReplaceRector;
use PHPRegex\Rector\PregSplitToExplodeRector;
use PHPRegex\Rector\Set\RegexSetList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Contract\Rector\RectorInterface;
use Rector\ValueObject\PhpVersion;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;

/**
 * The rules are frozen for 2.x: three Rector rules, none configurable, one
 * set that names them; only the preg_match() rule has a PHP floor, the one
 * str_contains() and its siblings need.
 */
final class RuleContractTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRules')]
    public function test_rule_is_a_rector_rule_without_configuration(string $rule): void
    {
        $this->assertTrue(is_subclass_of($rule, RectorInterface::class), $rule.' is a Rector rule.');
        $this->assertFalse(is_subclass_of($rule, ConfigurableRectorInterface::class), $rule.' takes no configuration.');
    }

    #[Test]
    public function test_rule_version_floor_is_the_preg_match_rule_only(): void
    {
        $rule = (new \ReflectionClass(PregMatchToStringComparisonRector::class))->newInstanceWithoutConstructor();
        $this->assertInstanceOf(MinPhpVersionInterface::class, $rule);
        $this->assertSame(PhpVersion::PHP_80, $rule->provideMinPhpVersion());

        $this->assertFalse(is_subclass_of(PregReplaceToStrReplaceRector::class, MinPhpVersionInterface::class));
        $this->assertFalse(is_subclass_of(PregSplitToExplodeRector::class, MinPhpVersionInterface::class));
    }

    #[Test]
    public function test_set_list_points_at_the_string_functions_set(): void
    {
        $expected = realpath(\dirname(__DIR__, 4).'/src/Rector/config/sets/string-functions.php');

        $this->assertIsString($expected);
        $this->assertSame($expected, realpath(RegexSetList::STRING_FUNCTIONS));
    }

    /**
     * @return iterable<string, array{rule: string}>
     */
    public static function provideRules(): iterable
    {
        yield 'preg_match' => ['rule' => PregMatchToStringComparisonRector::class];
        yield 'preg_replace' => ['rule' => PregReplaceToStrReplaceRector::class];
        yield 'preg_split' => ['rule' => PregSplitToExplodeRector::class];
    }
}
