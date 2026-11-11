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
 * A call that reads its pattern from an option, filter_var() under
 * FILTER_VALIDATE_REGEXP or Symfony's Regex constraint, has a pattern
 * argument only where its options may hold one. Options written at the
 * call that hold no such key hold no pattern: no argument. Options the
 * call does not spell out may hold one: an argument nothing is known of.
 * A spread is such options too: it may bring the key, or replace the
 * item written before it. So is a spread in the map of
 * preg_replace_callback_array(), whose keys are its patterns.
 *
 * Every case runs through both strategies.
 */
final class OptionsArgumentStateTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{call: string}>
     */
    public static function provideOptionsHoldingNoPattern(): iterable
    {
        yield 'filter_var(), no "options" key' => ['call' => "filter_var(\$v, FILTER_VALIDATE_REGEXP, ['flags' => FILTER_NULL_ON_FAILURE])"];
        yield 'filter_var(), no "regexp" key under "options"' => ['call' => "filter_var(\$v, FILTER_VALIDATE_REGEXP, ['options' => ['default' => 'none']])"];
        yield 'the Regex constraint, no "pattern" key' => ['call' => "new Regex(['message' => 'Nothing to match.'])"];
    }

    #[Test]
    #[DataProvider('provideOptionsHoldingNoPattern')]
    public function test_options_written_at_the_call_that_hold_no_pattern_give_no_argument(string $call): void
    {
        $code = self::form($call);
        $registry = PatternFunctionRegistry::create(['symfony-validator']);

        $this->assertExtractedByBoth([], $code, $registry);
        $this->assertArgumentsReadByBoth([], $code, $registry);
    }

    /**
     * @return iterable<string, array{call: string, argument: string}>
     */
    public static function provideOptionsTheCallDoesNotSpellOut(): iterable
    {
        yield 'filter_var(), options held by a variable' => [
            'call' => 'filter_var($v, FILTER_VALIDATE_REGEXP, $more)',
            'argument' => '$more',
        ];

        yield 'filter_var(), a spread after the "regexp" key' => [
            'call' => "filter_var(\$v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/replaced/', ...\$more]])",
            'argument' => "['options' => ['regexp' => '/replaced/', ...\$more]]",
        ];

        yield 'the Regex constraint, a spread alone' => [
            'call' => 'new Regex([...$more])',
            'argument' => '[...$more]',
        ];

        yield 'the Regex constraint, a spread after the "pattern" key' => [
            'call' => "new Regex(['pattern' => '/replaced/', ...\$more])",
            'argument' => "['pattern' => '/replaced/', ...\$more]",
        ];
    }

    #[Test]
    #[DataProvider('provideOptionsTheCallDoesNotSpellOut')]
    public function test_options_the_call_does_not_spell_out_are_an_argument_nothing_is_known_of(string $call, string $argument): void
    {
        $code = self::form($call);
        $registry = PatternFunctionRegistry::create(['symfony-validator']);

        $this->assertExtractedByBoth([], $code, $registry);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.');'],
        ], $code, $registry);
    }

    /**
     * The key written after the spread is the one PHP keeps.
     */
    #[Test]
    public function test_a_pattern_written_after_a_spread_is_read(): void
    {
        $code = self::form("new Regex([...\$more, 'pattern' => '/kept/'])");
        $registry = PatternFunctionRegistry::create(['symfony-validator']);

        $this->assertExtractedByBoth([
            ['pattern' => '/kept/', 'at' => "'/kept/'", 'source' => 'new Regex()', 'location' => null],
        ], $code, $registry);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/kept/'", 'source' => 'new Regex()'],
        ], $code, $registry);
    }

    /**
     * The keys a spread brings are not read, of a variable or of a
     * constant, opening the map or closing it: each spread is an
     * argument nothing is known of, beside the keys the call writes.
     */
    #[Test]
    public function test_a_spread_in_a_map_of_callbacks_is_an_argument_nothing_is_known_of(): void
    {
        $code = <<<'PHP'
            <?php

            final class Cleaner
            {
                private const HANDLERS = ['/spread/' => 'strtoupper'];

                public function run(string $s, array $more): array
                {
                    return [
                        preg_replace_callback_array([...self::HANDLERS, '/after/' => 'trim'], $s),
                        preg_replace_callback_array(['/before/' => 'trim', ...$more], $s),
                    ];
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/after/', 'at' => "'/after/'", 'source' => 'preg_replace_callback_array()', 'location' => null],
            ['pattern' => '/before/', 'at' => "'/before/'", 'source' => 'preg_replace_callback_array()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '...self::HANDLERS'],
            ['state' => 'constant', 'expression' => "'/after/'"],
            ['state' => 'constant', 'expression' => "'/before/'"],
            ['state' => 'opaque', 'expression' => '...$more'],
        ], $code);
    }

    private static function form(string $call): string
    {
        return "<?php\n\nuse Symfony\\Component\\Validator\\Constraints\\Regex;\n\nfunction constraint(string \$v, array \$more): mixed\n{\n    return ".$call.";\n}\n";
    }
}
