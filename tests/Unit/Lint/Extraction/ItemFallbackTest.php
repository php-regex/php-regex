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

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "$map['k'] ?? B": PHP takes B when it finds no item, and the item
 * otherwise. The fallback is left out only when the file proves the item
 * is there: the key names an item of the array, and the array is a
 * constant, or a variable that a statement of the body itself assigns
 * before the read, on every path to it. An array assigned in a branch
 * may not be assigned, a goto may jump over a statement of the body, and
 * a key the file does not prove may name nothing.
 *
 * PHP 8.4 (php -r): function run(bool $c) { if ($c) { goto skip; } $map
 * = ['k' => '/x/']; skip: return $map['k'] ?? '/y/'; } gives "/y/" for
 * true and "/x/" for false; with const MAP = ['' => '/a/', 'k' => '/b/'],
 * MAP['missing'] ?? '/y/' is "/y/".
 *
 * Every case runs through both strategies.
 */
final class ItemFallbackTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_an_item_of_an_array_the_body_always_assigns_leaves_the_fallback_out(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $map = ['k' => '/held/'];

                return preg_match($map['k'] ?? '/fallback/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/held/', 'at' => "'/held/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "\$map['k'] ?? '/fallback/'"],
        ], $code);
    }

    /**
     * The assignment written before the read stands in a branch. The one
     * that stands as a statement of the body is written after the read:
     * it proves nothing of what the read finds.
     */
    #[Test]
    public function test_an_array_assigned_in_a_branch_before_the_read_may_not_be_there(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                if ($flag) {
                    $map = ['k' => '/held/'];
                }

                $found = preg_match($map['k'] ?? '/fallback/', $s);
                $map = ['k' => '/later/'];

                return $found;
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/held/', 'at' => "'/held/'", 'location' => null],
            ['pattern' => '/fallback/', 'at' => "'/fallback/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "\$map['k'] ?? '/fallback/'"],
        ], $code);
    }

    #[Test]
    public function test_a_goto_may_jump_over_the_assignment_of_the_array(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                if ($flag) {
                    goto skip;
                }

                $map = ['k' => '/held/'];

                skip:
                return preg_match($map['k'] ?? '/fallback/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/held/', 'at' => "'/held/'", 'location' => null],
            ['pattern' => '/fallback/', 'at' => "'/fallback/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "\$map['k'] ?? '/fallback/'"],
        ], $code);
    }

    /**
     * The key is a parameter: it may name any item, or none, whatever
     * keys the array holds, the empty string among them.
     */
    #[Test]
    public function test_a_key_the_file_does_not_prove_may_name_no_item_of_an_array_holding_an_empty_key(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const MAP = ['' => '/unnamed/', 'k' => '/named/'];

                public function run(string $s, string $key): int
                {
                    return preg_match(self::MAP[$key] ?? '/fallback/', $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/unnamed/', 'at' => "'/unnamed/'", 'location' => null],
            ['pattern' => '/named/', 'at' => "'/named/'", 'location' => null],
            ['pattern' => '/fallback/', 'at' => "'/fallback/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "self::MAP[\$key] ?? '/fallback/'"],
        ], $code);
    }

    /**
     * The array is 32 constants from the call, the most a value goes
     * through: the item is read, and it is proved to be there as far as
     * it is read.
     */
    #[Test]
    public function test_an_item_read_through_32_constants_is_proved_there_as_far_as_it_is_read(): void
    {
        $constants = '';
        for ($i = 1; $i < 32; $i++) {
            $constants .= \sprintf("    private const C%d = self::C%d;\n", $i, $i + 1);
        }
        $constants .= "    private const C32 = ['k' => '/far/'];\n";
        $code = "<?php\n\nfinal class Matcher\n{\n".$constants."\n    public function run(string \$s): int\n    {\n        return preg_match(self::C1['k'] ?? '/fallback/', \$s);\n    }\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/far/', 'at' => "'/far/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "self::C1['k'] ?? '/fallback/'"],
        ], $code);
    }
}
