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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a name proves, decided once for both strategies: the items of an
 * array, the suffix a call appends, a pattern reported once, the classes
 * kept apart, and the 32 constants a value goes through at most, whether
 * the constants were folded before or not.
 */
final class InFileValueResolverTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_an_array_item_that_is_no_literal_leaves_the_next_items_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via self::PATTERNS'],
            ['pattern' => '/third/', 'at' => "'/third/'", 'location' => 'via self::PATTERNS'],
        ], <<<'PHP'
            <?php

            final class Cleaner
            {
                private const PATTERNS = ['/first/', 1, '', '/third/'];

                public function run(string $s): string
                {
                    return preg_replace(self::PATTERNS, '', $s);
                }
            }
            PHP);
    }

    /**
     * Nette's utf8: true appends "u" to the pattern a constant holds; a
     * call reading the constant without it reads the pattern as written,
     * at the same literal.
     */
    #[Test]
    public function test_one_literal_read_with_and_without_a_suffix_is_reported_for_both(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/both/u', 'at' => "'/both/'", 'source' => 'Strings::match()', 'location' => 'via self::RE'],
            ['pattern' => '/both/', 'at' => "'/both/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], <<<'PHP'
            <?php

            use Nette\Utils\Strings;

            final class Matcher
            {
                private const RE = '/both/';

                public function run(string $s): bool
                {
                    return null !== Strings::match($s, self::RE, utf8: true) && 1 === preg_match(self::RE, $s);
                }
            }
            PHP, PatternFunctionRegistry::native()->withPresets([InteropPresets::NETTE_UTILS]));
    }

    /**
     * A pattern already reported is passed over; the items after it are
     * still read.
     */
    #[Test]
    public function test_an_array_holding_a_pattern_already_reported_reads_its_other_items(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/shared/', 'at' => "'/shared/'", 'source' => 'preg_match()', 'location' => 'via self::SHARED'],
            ['pattern' => '/own/', 'at' => "'/own/'", 'source' => 'preg_replace()', 'location' => 'via self::LIST'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const SHARED = '/shared/';
                private const LIST = [self::SHARED, '/own/'];

                public function one(string $s): int
                {
                    return preg_match(self::SHARED, $s);
                }

                public function all(string $s): string
                {
                    return preg_replace(self::LIST, '', $s);
                }
            }
            PHP);
    }

    /**
     * Two classes whose names and constants run together ("A::bc",
     * "Ab::c"), or one constant name in two classes, are kept apart.
     */
    #[Test]
    public function test_the_constants_of_two_classes_are_kept_apart(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/from-a/', 'at' => "'/from-a/'", 'location' => 'via A::bc'],
            ['pattern' => '/from-ab/', 'at' => "'/from-ab/'", 'location' => 'via Ab::c'],
            ['pattern' => '/left/', 'at' => "'/left/'", 'location' => 'via Left::RE'],
            ['pattern' => '/right/', 'at' => "'/right/'", 'location' => 'via Right::RE'],
        ], <<<'PHP'
            <?php

            final class A
            {
                public const bc = '/from-a/';
            }

            final class Ab
            {
                public const c = '/from-ab/';
            }

            final class Left
            {
                public const RE = '/left/';
            }

            final class Right
            {
                public const RE = '/right/';
            }

            preg_match(A::bc, $s);
            preg_match(Ab::c, $s);
            preg_match(Left::RE, $s);
            preg_match(Right::RE, $s);
            PHP);
    }

    /**
     * A variable assigned a constant goes through as many constants as the
     * call would: 32 at most.
     */
    #[Test]
    public function test_a_variable_reads_a_chain_of_at_most_32_constants(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/end/', 'at' => "'/end/'", 'location' => 'via $re'],
        ], self::chain(32, <<<'PHP'
            function run(string $s): int
            {
                $re = Chain::C1;

                return preg_match($re, $s);
            }
            PHP));

        $this->assertExtractedByBoth([], self::chain(33, <<<'PHP'
            function run(string $s): int
            {
                $re = Chain::C1;

                return preg_match($re, $s);
            }
            PHP));
    }

    /**
     * A constant folded once is read again, from the same depth, through
     * what was folded.
     */
    #[Test]
    public function test_a_folded_constant_is_read_again_at_the_same_depth(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/end/', 'at' => "'/end/'", 'location' => 'via Chain::C1'],
            ['pattern' => '/end/i', 'at' => "Chain::C1 . 'i'", 'location' => null],
        ], self::chain(32, <<<'PHP'
            preg_match(Chain::C1, $s);
            preg_match(Chain::C1 . 'i', $s);
            PHP));
    }

    /**
     * Folded or not, a constant is read through 32 constants at most: one
     * more, through a constant folded before, is one too many.
     */
    #[Test]
    public function test_a_folded_constant_obeys_the_same_limit_one_constant_deeper(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/end/', 'at' => "'/end/'", 'location' => 'via Chain::C1'],
        ], self::chain(32, <<<'PHP'
            preg_match(Chain::C1, $s);
            preg_match(Chain::D, $s);
            PHP));
    }

    /**
     * A constant too deep for one call is read by a call that reaches it
     * from closer: the refusal is not kept for the next one.
     */
    #[Test]
    public function test_a_constant_too_deep_for_one_call_is_read_by_a_closer_one(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/end/', 'at' => "'/end/'", 'location' => 'via Chain::C1'],
        ], self::chain(32, <<<'PHP'
            preg_match(Chain::D, $s);
            preg_match(Chain::C1, $s);
            PHP));
    }

    /**
     * A file declaring Chain::C1 through Chain::C{$length}, each the next
     * one and the last "/end/", and Chain::D, C1 followed by "i"; then
     * $code.
     */
    private static function chain(int $length, string $code): string
    {
        $constants = '';
        for ($i = 1; $i < $length; $i++) {
            $constants .= '    public const C'.$i.' = self::C'.($i + 1).";\n";
        }
        $constants .= '    public const C'.$length." = '/end/';\n";
        $constants .= "    public const D = self::C1 . 'i';\n";

        return "<?php\n\nfinal class Chain\n{\n".$constants."}\n\n".$code."\n";
    }
}
