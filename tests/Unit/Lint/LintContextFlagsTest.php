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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\Support\FlagScope;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint context reads the flags in effect at each node from those at
 * the start of the pattern, the caseless restriction (*CASELESS_RESTRICT)
 * sets among them: "(?-r)" lifts it as it lifts r, and "(?r)" sets it
 * again. Under iu, "[^\x{212A}]" refuses the "k" the Kelvin sign folds to,
 * and takes it under the restriction. A rule asks at the class, under the
 * flags in effect there.
 */
final class LintContextFlagsTest extends TestCase
{
    use PcreGates;

    #[Test]
    #[DataProvider('provideRestrictions')]
    public function test_the_caseless_restriction_holds_until_an_option_setting_lifts_it(string $pattern, string $oracle, bool $takesK): void
    {
        $this->requiresCompile($pattern);
        $this->assertSame((int) $takesK, preg_match($oracle, 'k'), 'Oracle: '.$oracle.' on "k".');

        $tree = RegexParser::create(['cache' => null])->parse($pattern);
        $context = new LintContext(
            new PatternInfo($tree->flags, $tree->delimiter, '', true),
            new GroupIndex(0, [], [], [], false),
            CharSetAnalyzer::forRegex($tree, true),
        );
        $class = self::theClass($tree->pattern);
        $flags = FlagScope::flagsAt($class, $tree->pattern, $context->activeFlags());
        $this->assertNotNull($flags);

        $this->assertSame($takesK, $context->firstChars($class, $flags)->intersects(ByteCharSet::fromChar('k')));
    }

    /**
     * @return iterable<string, array{pattern: string, oracle: string, takesK: bool}>
     */
    public static function provideRestrictions(): iterable
    {
        yield 'the restriction' => ['pattern' => '/(*CASELESS_RESTRICT)[^\x{212A}]/iu', 'oracle' => '/(*CASELESS_RESTRICT)\A[^\x{212A}]\z/iu', 'takesK' => true];
        yield 'lifted after it' => ['pattern' => '/(*CASELESS_RESTRICT)(?-r)[^\x{212A}]/iu', 'oracle' => '/(*CASELESS_RESTRICT)(?-r)\A[^\x{212A}]\z/iu', 'takesK' => false];
        yield 'lifted in a group' => ['pattern' => '/(*CASELESS_RESTRICT)(?-r:[^\x{212A}])/iu', 'oracle' => '/(*CASELESS_RESTRICT)\A(?-r:[^\x{212A}])\z/iu', 'takesK' => false];
        yield 'lifted, then set again' => ['pattern' => '/(*CASELESS_RESTRICT)(?-r)(?r)[^\x{212A}]/iu', 'oracle' => '/(*CASELESS_RESTRICT)(?-r)(?r)\A[^\x{212A}]\z/iu', 'takesK' => true];
        yield 'no restriction' => ['pattern' => '/[^\x{212A}]/iu', 'oracle' => '/\A[^\x{212A}]\z/iu', 'takesK' => false];
    }

    /**
     * A lookaround reads nothing, whatever its body reads: the options
     * group inside it takes no character either. "(?=(?i:b))b" starts
     * with "b" alone, as the engine takes it.
     */
    #[Test]
    #[DataProvider('provideLookarounds')]
    public function test_a_lookaround_holding_an_option_group_reads_nothing(string $pattern, string $oracle, bool $fromStart, string $takes, string $never): void
    {
        $this->assertSame(1, preg_match($oracle, $takes), 'Oracle: '.$oracle.' on "'.$takes.'".');
        $this->assertSame(0, preg_match($oracle, $never), 'Oracle: '.$oracle.' on "'.$never.'".');

        $tree = RegexParser::create(['cache' => null])->parse($pattern);
        $context = new LintContext(
            new PatternInfo($tree->flags, $tree->delimiter, '', false),
            new GroupIndex(0, [], [], [], false),
            CharSetAnalyzer::forRegex($tree),
        );
        $set = $fromStart ? $context->firstChars($tree->pattern) : $context->lastChars($tree->pattern);

        $this->assertTrue($set->intersects(ByteCharSet::fromChar($takes)), $pattern);
        $this->assertFalse($set->intersects(ByteCharSet::fromChar($never)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, oracle: string, fromStart: bool, takes: string, never: string}>
     */
    public static function provideLookarounds(): iterable
    {
        yield 'a lookahead before the first character' => ['pattern' => '/(?=(?i:b))b/', 'oracle' => '/\A(?=(?i:b))b\z/', 'fromStart' => true, 'takes' => 'b', 'never' => 'B'];
        yield 'a negative lookahead before the first character' => ['pattern' => '/(?!(?i:c))b/', 'oracle' => '/\A(?!(?i:c))[bC]\z/', 'fromStart' => true, 'takes' => 'b', 'never' => 'C'];
        yield 'a lookbehind after the last character' => ['pattern' => '/b(?<=(?i:b))/', 'oracle' => '/\Ab(?<=(?i:b))\z/', 'fromStart' => false, 'takes' => 'b', 'never' => 'B'];
        yield 'a lookahead in a group before the first character' => ['pattern' => '/(?:(?=(?i:b)))b/', 'oracle' => '/\A(?:(?=(?i:b)))b\z/', 'fromStart' => true, 'takes' => 'b', 'never' => 'B'];
    }

    private static function theClass(NodeInterface $node): CharClassNode
    {
        if ($node instanceof CharClassNode) {
            return $node;
        }

        foreach ($node->getChildren() as $child) {
            try {
                return self::theClass($child);
            } catch (\LogicException) {
            }
        }

        throw new \LogicException('No class in the pattern.');
    }
}
