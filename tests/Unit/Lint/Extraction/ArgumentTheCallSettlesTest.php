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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Some pattern arguments are settled where the call is written, before
 * anything is known of the names they hold: a pattern whose flags another
 * argument decides at run time, options that are no array the call
 * spells out, an item of a list taken by reference. Each is still a
 * pattern argument of its call, counted with the state it ends in and
 * placed where it stands; options that hold no pattern are no argument
 * at all.
 *
 * Every case runs through both strategies.
 */
final class ArgumentTheCallSettlesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Nette appends "u" to the pattern when utf8 is true: with a utf8
     * the file does not spell, the flags are not known, and the pattern
     * is not read, a literal or not.
     *
     * @return iterable<string, array{setup: string, pattern: string}>
     */
    public static function providePatternsWhoseFlagsAreDecidedAtRunTime(): iterable
    {
        yield 'a literal' => ['setup' => '', 'pattern' => "'/literal/'"];
        yield 'a variable' => ['setup' => "\$re = '/variable/';", 'pattern' => '$re'];
        yield 'a constant' => ['setup' => '', 'pattern' => 'PATTERN'];
    }

    #[Test]
    #[DataProvider('providePatternsWhoseFlagsAreDecidedAtRunTime')]
    public function test_a_pattern_whose_flags_are_decided_at_run_time_is_an_argument_left_unread(string $setup, string $pattern): void
    {
        $code = "<?php\n\nuse Nette\\Utils\\Strings;\n\nconst PATTERN = '/constant/';\n\nfunction run(string \$s, bool \$flag): void\n{\n    ".$setup."\n    Strings::match(\$s, ".$pattern.", utf8: \$flag);\n}\n";
        $registry = PatternFunctionRegistry::create(['nette-utils']);

        $this->assertExtractedByBoth([], $code, $registry);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $pattern, 'at' => $pattern.', utf8', 'source' => 'Strings::match()'],
        ], $code, $registry);
    }

    /**
     * @return iterable<string, array{options: string}>
     */
    public static function provideOptionsTheCallDoesNotSpellOut(): iterable
    {
        yield 'a variable' => ['options' => '$options'];
        yield 'an array whose options are a variable' => ['options' => "['options' => \$regexpOptions]"];
        yield 'an array whose options are spread' => ['options' => "['options' => [...\$more]]"];
    }

    /**
     * The options may hold a "regexp": the argument is counted, and
     * nothing is read of it.
     */
    #[Test]
    #[DataProvider('provideOptionsTheCallDoesNotSpellOut')]
    public function test_options_the_call_does_not_spell_out_are_an_argument_left_unread(string $options): void
    {
        $code = "<?php\n\nfilter_var(\$v, FILTER_VALIDATE_REGEXP, ".$options.");\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $options, 'at' => $options.');', 'source' => 'filter_var()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{options: string}>
     */
    public static function provideOptionsThatHoldNoPattern(): iterable
    {
        yield 'options with no regexp' => ['options' => "['options' => ['default' => 'x']]"];
        yield 'an array with no options' => ['options' => "['flags' => FILTER_NULL_ON_FAILURE]"];
    }

    #[Test]
    #[DataProvider('provideOptionsThatHoldNoPattern')]
    public function test_options_that_hold_no_pattern_are_no_argument(string $options): void
    {
        $code = "<?php\n\nfilter_var(\$v, FILTER_VALIDATE_REGEXP, ".$options.");\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([], $code);
    }

    /**
     * Each row gives the list and the item taken by reference, which is
     * the first one or not: the argument is the variable, placed at the
     * variable, not at the "&" before it.
     *
     * @return iterable<string, array{list: string, at: string}>
     */
    public static function provideListsHoldingAReference(): iterable
    {
        yield 'the first item' => ['list' => "[&\$taken, '/literal/']", 'at' => '$taken, '];
        yield 'the last item' => ['list' => "['/literal/', &\$taken]", 'at' => '$taken]'];
        yield 'the last item, after a space' => ['list' => "['/literal/', & \$taken]", 'at' => '$taken]'];
    }

    /**
     * A reference lets anything write the variable: it is not read. The
     * literal beside it is.
     */
    #[Test]
    #[DataProvider('provideListsHoldingAReference')]
    public function test_an_item_taken_by_reference_is_an_argument_placed_at_its_variable(string $list, string $at): void
    {
        $code = "<?php\n\nfunction run(string \$s): ?string\n{\n    \$taken = '/a/';\n\n    return preg_replace(".$list.", '', \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/literal/', 'at' => "'/literal/'", 'source' => 'preg_replace()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$taken', 'at' => $at, 'source' => 'preg_replace()'],
            ['state' => 'constant', 'expression' => "'/literal/'", 'source' => 'preg_replace()'],
        ], $code);
    }
}
