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

use PHPRegex\Rector\AlphabeticAssertionToClassicRector;
use PHPRegex\Rector\EscapeLiteralBraceRector;
use PHPRegex\Rector\EscapeNulByteRector;
use PHPRegex\Rector\PossessiveRepeatOnceRector;
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
 * The rules are frozen for 2.x: six Rector rules, none configurable, two
 * sets that name them; only the preg_match() rule has a PHP floor, the one
 * str_contains() and its siblings need. The literal-brace rule's docblock
 * contract is pinned here too: refactor() receives a FuncCall and returns
 * that same call, rewritten, or null.
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
        $this->assertFalse(is_subclass_of(EscapeLiteralBraceRector::class, MinPhpVersionInterface::class));
    }

    #[Test]
    public function test_set_list_points_at_the_string_functions_set(): void
    {
        $expected = realpath(\dirname(__DIR__, 4).'/src/Rector/config/sets/string-functions.php');

        $this->assertIsString($expected);
        $this->assertSame($expected, realpath(RegexSetList::STRING_FUNCTIONS));
    }

    #[Test]
    public function test_set_list_points_at_the_pcre_upgrade_set(): void
    {
        $expected = realpath(\dirname(__DIR__, 4).'/src/Rector/config/sets/pcre-upgrade.php');

        $this->assertIsString($expected);
        $this->assertSame($expected, realpath(RegexSetList::PCRE_UPGRADE));
    }

    /**
     * The 1.x to 2.0 set ships with the Rector rules, at a path the constant
     * names whatever the install layout: split or monorepo.
     */
    #[Test]
    public function test_set_list_points_at_the_upgrade_2_0_set(): void
    {
        $expected = realpath(\dirname(__DIR__, 4).'/src/Rector/config/sets/upgrade-2.0.php');

        $this->assertIsString($expected);
        $this->assertSame($expected, realpath(RegexSetList::UPGRADE_2_0));
    }

    #[Test]
    public function test_literal_brace_refactor_returns_the_same_call_it_received(): void
    {
        $docComment = (string) (new \ReflectionMethod(EscapeLiteralBraceRector::class, 'refactor'))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@param\s+(\S+)\s+\$node\b/', $docComment, $param),
            'refactor() must document its $node as FuncCall|StaticCall|Class_: the rule subscribes to those three alone.',
        );
        $this->assertSame(
            1,
            preg_match('/@return\s+(\S+)/', $docComment, $return),
            'refactor() must document what it returns: the same call, its pattern literal rewritten, the same'
            .' class, its private constants rewritten, or null when it leaves the code alone.',
        );

        $this->assertSame(
            'FuncCall|StaticCall|Class_',
            $param[1],
            sprintf('The @param for $node in refactor() must name FuncCall|StaticCall|Class_, not %s.', $param[1]),
        );

        $members = array_values(array_filter(array_map(trim(...), explode('|', $return[1])), static fn (string $member): bool => '' !== $member));
        sort($members);

        $this->assertSame(
            ['Class_', 'FuncCall', 'StaticCall', 'null'],
            $members,
            sprintf(
                'The @return of refactor() must say FuncCall|StaticCall|Class_|null, not %s: every non-null path returns'
                .' the very $node it received (the native ?Node stays — RectorInterface owns that contract).',
                trim($return[1]),
            ),
        );
    }

    /**
     * @return iterable<string, array{rule: string}>
     */
    public static function provideRules(): iterable
    {
        yield 'preg_match' => ['rule' => PregMatchToStringComparisonRector::class];
        yield 'preg_replace' => ['rule' => PregReplaceToStrReplaceRector::class];
        yield 'preg_split' => ['rule' => PregSplitToExplodeRector::class];
        yield 'literal brace' => ['rule' => EscapeLiteralBraceRector::class];
        yield 'alphabetic assertion' => ['rule' => AlphabeticAssertionToClassicRector::class];
        yield 'NUL byte' => ['rule' => EscapeNulByteRector::class];
        yield 'possessive repeat once' => ['rule' => PossessiveRepeatOnceRector::class];
    }
}
