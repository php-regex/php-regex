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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The native APIs besides preg_* that compile a delimited PCRE pattern,
 * always read, like preg_*, whatever the presets.
 *
 * Checked with the engine: new RegexIterator(new ArrayIterator(['a']),
 * '/(/') throws "RegexIterator::__construct(): Compilation failed: missing
 * closing parenthesis at offset 1", RecursiveRegexIterator the same;
 * filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' =>
 * '/(/']]) warns "filter_var(): Compilation failed: missing closing
 * parenthesis at offset 1". Parameters, by reflection:
 * RegexIterator::__construct(iterator, pattern, ...), filter_var(value,
 * filter, options), filter_input(type, var_name, filter, options).
 */
final class NativePatternApisTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_regex_iterators_are_read_at_their_second_argument(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/global-namespace/', 'at' => "'/global-namespace/'", 'location' => null],
            ['pattern' => '/recursive/', 'at' => "'/recursive/'"],
            ['pattern' => '/named/', 'at' => "'/named/'"],
        ], <<<'PHP'
            <?php

            $a = new RegexIterator($it, '/global-namespace/', RegexIterator::MATCH);
            $b = new \RecursiveRegexIterator($recursive, '/recursive/');
            $c = new RegexIterator(pattern: '/named/', iterator: $it);
            PHP, PatternFunctionRegistry::create([]));
    }

    /**
     * Class names never fall back to the global namespace: an unimported
     * RegexIterator in a namespace is that namespace's class.
     */
    #[Test]
    public function test_a_regex_iterator_is_matched_on_its_fully_qualified_name(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/imported/', 'at' => "'/imported/'"],
            ['pattern' => '/qualified/', 'at' => "'/qualified/'"],
        ], <<<'PHP'
            <?php

            namespace App;

            use RecursiveRegexIterator;

            $a = new RegexIterator($it, '/unimported/');
            $b = new RecursiveRegexIterator($it, '/imported/');
            $c = new \RegexIterator($it, '/qualified/');
            PHP, PatternFunctionRegistry::create([]));
    }

    #[Test]
    public function test_filter_validate_regexp_options_are_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/filter-var/', 'at' => "'/filter-var/'", 'location' => null],
            ['pattern' => '/filter-var-qualified/', 'at' => "'/filter-var-qualified/'"],
            ['pattern' => '/filter-var-flags-first/', 'at' => "'/filter-var-flags-first/'"],
            ['pattern' => '/filter-var-named/', 'at' => "'/filter-var-named/'"],
            ['pattern' => '/filter-input/', 'at' => "'/filter-input/'"],
            ['pattern' => '/in-a-namespace/', 'at' => "'/in-a-namespace/'"],
        ], <<<'PHP'
            <?php

            namespace App;

            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/filter-var/']]);
            \filter_var($v, \FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/filter-var-qualified/']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['flags' => FILTER_NULL_ON_FAILURE, 'options' => ['default' => 'x', 'regexp' => '/filter-var-flags-first/']]);
            filter_var(options: ['options' => ['regexp' => '/filter-var-named/']], filter: FILTER_VALIDATE_REGEXP, value: $v);
            filter_input(INPUT_GET, 'q', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/filter-input/']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/in-a-namespace/')));
            PHP, PatternFunctionRegistry::create([]));
    }

    /**
     * Only the FILTER_VALIDATE_REGEXP constant at the filter position and an
     * array literal of options say the option is a pattern.
     */
    #[Test]
    public function test_filter_var_without_the_regexp_filter_or_literal_options_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            filter_var($v, FILTER_VALIDATE_INT, ['options' => ['regexp' => '/other-filter/']]);
            filter_var($v, $filter, ['options' => ['regexp' => '/unknown-filter/']]);
            filter_var($v, 272, ['options' => ['regexp' => '/filter-as-int/']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, $options);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => $regexpOptions]);
            filter_var($v, FILTER_VALIDATE_REGEXP, ['regexp' => '/not-under-options/']);
            filter_input(INPUT_GET, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/filter-input-shifted/']]);
            new RegexIterator($it, '/control/');
            PHP, PatternFunctionRegistry::create([]));
    }

    /**
     * The options are found whatever stands around them: a list item or a
     * call before the key, a parenthesis around the array. An array added
     * to another is no array literal: PHP keeps the left operand's keys,
     * but the file does not say what the right one holds.
     */
    #[Test]
    public function test_filter_var_options_are_found_in_any_array_literal(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/after-a-list-item/', 'at' => "'/after-a-list-item/'"],
            ['pattern' => '/after-a-call/', 'at' => "'/after-a-call/'"],
            ['pattern' => '/parenthesised/', 'at' => "'/parenthesised/'"],
            ['pattern' => '/trailing-comma/', 'at' => "'/trailing-comma/'"],
        ], <<<'CODE'
            <?php

            filter_var($v, FILTER_VALIDATE_REGEXP, [$unused, 'options' => ['regexp' => '/after-a-list-item/']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, [strtolower('FLAGS') => 0, 'options' => ['regexp' => '/after-a-call/']]);
            filter_var($v, FILTER_VALIDATE_REGEXP, (['options' => (['regexp' => '/parenthesised/'])]));
            filter_var($v, FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/trailing-comma/'),));
            filter_var($v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/added-to-another-array/']] + $defaults);
            CODE, PatternFunctionRegistry::create([]));
    }

    /**
     * filter_var('abc', (FILTER_VALIDATE_REGEXP), ['options' => ['regexp'
     * => '/(/']]) warns "filter_var(): Compilation failed: missing closing
     * parenthesis at offset 1": the parenthesis changes nothing. The
     * parser strategy reads the pattern; the token strategy does not.
     */
    #[Test]
    public function test_a_parenthesised_filter_constant_still_selects_the_regexp_filter(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/parenthesised-filter/', 'at' => "'/parenthesised-filter/'"],
        ], <<<'CODE'
            <?php

            filter_var($v, (FILTER_VALIDATE_REGEXP), ['options' => ['regexp' => '/parenthesised-filter/']]);
            CODE, PatternFunctionRegistry::create([]));
    }

    /**
     * mb_ereg* and mb_split() take Oniguruma syntax, no PCRE.
     */
    #[Test]
    public function test_mb_ereg_is_never_extracted(): void
    {
        $this->assertSame(['/control/'], $this->patternsReadByBoth(<<<'PHP'
            <?php

            mb_ereg('(a', $s);
            mb_eregi('(a', $s);
            mb_ereg_replace('(a', 'b', $s);
            mb_eregi_replace('(a', 'b', $s);
            mb_ereg_match('(a', $s);
            mb_ereg_search_init($s, '(a');
            mb_split('(a', $s);
            preg_match('/control/', $s);
            PHP, PatternFunctionRegistry::defaults()));
    }
}
