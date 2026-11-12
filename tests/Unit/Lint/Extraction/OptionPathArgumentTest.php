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
 * The argument of a call that reads its pattern under a key of its
 * options, filter_var() under "options" => "regexp": the pattern the
 * call spells out is the argument; options that are no array the call
 * writes, or that a spread may complete, may hold a pattern nothing is
 * known of; options written without the key hold none, and give no
 * argument.
 *
 * PHP 8.4 (php -r): filter_var('abc', FILTER_VALIDATE_REGEXP, ['options'
 * => ['regexp' => '/^a/']]) is "abc". Without the key, ['options' =>
 * ['default' => 'x']] or ['flags' => FILTER_NULL_ON_FAILURE], the call
 * throws 'filter_var(): "regexp" option is missing': no pattern is
 * compiled. With $more = ['regexp' => '/^b/'], ['options' => ['regexp'
 * => '/^a/', ...$more]] is false and ['options' => ['default' => 'x',
 * ...$more]] is "x": the pattern of the spread is the one compiled.
 * With $outer = ['options' => ['regexp' => '/^c/']], ['flags' => 0,
 * ...$outer] is false, and [...$outer, 'options' => ['regexp' =>
 * '/^a/']] is "abc": a key written after the spread replaces it.
 *
 * Both strategies read the same.
 */
final class OptionPathArgumentTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_pattern_the_options_spell_out_is_the_argument(): void
    {
        $code = <<<'PHP'
            <?php

            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/spelled/']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, [...$outer, 'options' => ['regexp' => '/after-a-spread/']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => [...$more, 'regexp' => '/after-an-inner-spread/']]);
            PHP;

        $this->assertSame(['/spelled/', '/after-a-spread/', '/after-an-inner-spread/'], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/spelled/'"],
            ['state' => 'constant', 'expression' => "'/after-a-spread/'"],
            ['state' => 'constant', 'expression' => "'/after-an-inner-spread/'"],
        ], $code);
    }

    #[Test]
    public function test_options_the_call_does_not_spell_out_are_an_argument_nothing_is_known_of(): void
    {
        $code = <<<'PHP'
            <?php

            filter_var($v, FILTER_VALIDATE_REGEXP, $options);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => $regexpOptions]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['default' => 'x', ...$more]]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/replaced/', ...$more]]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['flags' => 0, ...$outer]);
            PHP;

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$options'],
            ['state' => 'opaque', 'expression' => "['options' => \$regexpOptions]"],
            ['state' => 'opaque', 'expression' => "['options' => ['default' => 'x', ...\$more]]"],
            ['state' => 'opaque', 'expression' => "['options' => ['regexp' => '/replaced/', ...\$more]]"],
            ['state' => 'opaque', 'expression' => "['flags' => 0, ...\$outer]"],
        ], $code);
    }

    #[Test]
    public function test_options_written_without_the_key_give_no_argument(): void
    {
        $code = <<<'PHP'
            <?php

            filter_var($v, FILTER_VALIDATE_REGEXP, ['flags' => FILTER_NULL_ON_FAILURE]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['default' => 'x']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => []]);
            filter_var($v, FILTER_VALIDATE_REGEXP, []);
            PHP;

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertSame([], $this->argumentsReadByBoth($code));
    }
}
