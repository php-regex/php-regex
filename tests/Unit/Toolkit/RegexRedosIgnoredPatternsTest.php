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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The patterns the ReDoS analysis skips are an option of the facade, which
 * hands them to the analyzer it builds; the parser takes none, and says
 * where they go.
 */
final class RegexRedosIgnoredPatternsTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_the_facade_skips_the_patterns_it_is_given(): void
    {
        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/(a+)+$/')->severity);

        $regex = Regex::create(['cache' => null, 'redos_ignored_patterns' => ['/(a+)+$/']]);

        $this->assertSame(RedosProof::NotAnalyzed, $regex->redos('/(a+)+$/')->proof);
        $this->assertSame(RedosProof::NotAnalyzed, $regex->withTarget(new PcreTarget(80200, '10.40', 'C'))->redos('/(a+)+$/')->proof);
        $this->assertSame(RedosSeverity::Critical, $regex->redos('/(b+)+$/')->severity);
    }

    /**
     * The analyzer built on a parser is told the patterns itself, as the
     * facade tells the one it builds: both skip the same pattern.
     */
    #[Test]
    public function test_the_analyzer_built_on_a_parser_takes_the_patterns_as_its_argument(): void
    {
        $parser = RegexParser::create(['cache' => null]);

        $this->assertSame(RedosProof::NotAnalyzed, (new RedosAnalyzer($parser, ignoredPatterns: ['/(a+)+$/']))->analyze('/(a+)+$/')->proof);
        $this->assertSame(RedosSeverity::Critical, (new RedosAnalyzer($parser))->analyze('/(a+)+$/')->severity);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('invalidLists')]
    public function test_the_facade_refuses_a_list_that_holds_no_patterns(array $options, string $message): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains($message);

        // The options as a configuration file gives them, not as PHPStan
        // checks a literal array.
        (new \ReflectionMethod(Regex::class, 'create'))->invoke(null, $options);
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, message: string}>
     */
    public static function invalidLists(): iterable
    {
        yield 'a string' => ['options' => ['redos_ignored_patterns' => '/(a+)+$/'], 'message' => '"redos_ignored_patterns" must be a list of strings.'];
        yield 'an integer in the list' => ['options' => ['redos_ignored_patterns' => ['/a/', 123]], 'message' => '"redos_ignored_patterns" must contain only strings.'];
    }

    /**
     * Every other key is the parser's to read, and to refuse.
     */
    #[Test]
    public function test_the_facade_hands_every_other_option_to_the_parser(): void
    {
        $regex = Regex::create(['max_pattern_length' => 5, 'redos_ignored_patterns' => []]);

        $this->assertFalse($regex->validate('/abcdef/')->isValid);

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('Unknown option(s): unknown_option.');

        (new \ReflectionMethod(Regex::class, 'create'))->invoke(null, ['unknown_option' => true, 'redos_ignored_patterns' => []]);
    }
}
