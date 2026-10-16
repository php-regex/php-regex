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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * From PCRE2 10.40, "\p{Han}" names the characters whose script extensions
 * hold Han; before, those whose script is Han. U+3001 (ideographic comma) is
 * Common with Han among its extensions: preg_match('/^\p{Han}$/u', "\u{3001}")
 * is 0 on PHP 8.1.34 with its bundled PCRE2 10.39, and 1 on PHP 8.2.34 with
 * 10.40. The automata read the property as the target does. Each row is
 * preg_match()'s on those two engines.
 */
final class ScriptExtensionsByTargetTest extends TestCase
{
    use PcreGates;

    #[Test]
    #[DataProvider('provideScriptPatterns')]
    public function test_a_script_name_means_the_script_before_pcre2_10_40_and_its_extensions_after(string $pattern, bool $matchesBefore, bool $matchesAfter): void
    {
        // The running engine is asked for the set: only one that reads
        // "sc:" can answer for an older target.
        $this->requiresPcre2('10.40', $pattern);

        $before = new LanguageSolver(RegexParser::create(['cache' => null, 'php_version' => 80100]));
        $after = new LanguageSolver(RegexParser::create(['cache' => null, 'php_version' => 80200]));

        $this->assertSame(!$matchesBefore, $before->intersection($pattern, "/^\u{3001}$/u")->isEmpty, $pattern.' for PHP 8.1');
        $this->assertSame(!$matchesAfter, $after->intersection($pattern, "/^\u{3001}$/u")->isEmpty, $pattern.' for PHP 8.2');
    }

    /**
     * @return iterable<string, array{pattern: string, matchesBefore: bool, matchesAfter: bool}>
     */
    public static function provideScriptPatterns(): iterable
    {
        yield 'Han' => ['pattern' => '/^\p{Han}$/u', 'matchesBefore' => false, 'matchesAfter' => true];
        yield 'not Han' => ['pattern' => '/^\P{Han}$/u', 'matchesBefore' => true, 'matchesAfter' => false];
        yield 'not Han, by a caret' => ['pattern' => '/^\p{^Han}$/u', 'matchesBefore' => true, 'matchesAfter' => false];
        yield 'Han in a class' => ['pattern' => '/^[\p{Han}a]$/u', 'matchesBefore' => false, 'matchesAfter' => true];
        yield 'outside a class of Han' => ['pattern' => '/^[^\p{Han}]$/u', 'matchesBefore' => true, 'matchesAfter' => false];
        yield 'Common, the script of U+3001' => ['pattern' => '/^\p{Common}$/u', 'matchesBefore' => true, 'matchesAfter' => true];
        yield 'a general category, unchanged' => ['pattern' => '/^\p{Po}$/u', 'matchesBefore' => true, 'matchesAfter' => true];
    }
}
