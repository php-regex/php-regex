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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Exception\ConfigException;
use Psalm\Internal\Analyzer\IssueData;

/**
 * Patterns are judged for max(the PHP version Psalm analyses for, 8.2), with
 * the PCRE2 that version bundles, unless the plugin's XML options say
 * otherwise: <phpVersion> ('runtime', '8.5', or a PHP_VERSION_ID like 80200)
 * and <pcreVersion> ('10.40'), as the PHPStan extension's phpVersion and
 * pcreVersion.
 *
 * The plugin narrows only a pattern the running engine compiles and the
 * target accepts; below PHP 8.2 analysed, a pattern using the n modifier is
 * not narrowed, since PHP refuses it there. The expectations that depend on
 * the running engine are guarded by it, as on the 10.40 and 10.42 CI images.
 */
final class PsalmPluginTargetTest extends TestCase
{
    private const FILE = 'Target/target.php';

    private const KEEP = '/(?=a\Ka)(a)/';

    private const ASCII = '/(?aD)(x)/';

    private const NO_AUTO_CAPTURE = '/(a)(?<x>b)/n';

    private const DEFAULT = 'array<array-key, string>';

    private const SHAPES = [
        self::KEEP => "array{0: string, 1: 'a'}",
        self::ASCII => "array{0: 'x', 1: 'x'}",
        self::NO_AUTO_CAPTURE => "array{0: 'ab', x: 'b', 1: 'b'}",
    ];

    /**
     * @param array<string, string>      $options  the plugin's XML options
     * @param array<string, string|null> $reported the patterns reported, each with the target its message names (null: not checked)
     * @param list<string>               $narrowed the patterns narrowed, when the running engine compiles them
     */
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_plugin_judges_for_the_target(string $phpVersion, array $options, array $reported, array $narrowed): void
    {
        $issues = PsalmRun::inFile(PsalmRun::issues([self::FILE], $phpVersion, $options), self::FILE);

        $found = [];
        foreach ($issues as $issue) {
            if ('Trace' === $issue->type) {
                continue;
            }
            $this->assertSame('InvalidRegexPattern', $issue->type, PsalmRun::describe($issue));
            $found[$issue->selected_text] = $issue->message;
        }
        $expected = [];
        foreach ($reported as $pattern => $target) {
            $expected["'".$pattern."'"] = $target;
        }
        ksort($found);
        ksort($expected);
        $this->assertSame(array_keys($expected), array_keys($found), 'The patterns reported.');
        foreach ($expected as $argument => $target) {
            if (null !== $target) {
                $this->assertStringStartsWith('Regex pattern is invalid for '.$target.': ', $found[$argument]);
            }
        }

        foreach (self::SHAPES as $pattern => $shape) {
            $expectedType = \in_array($pattern, $narrowed, true) && PsalmRun::runningEngineCompiles($pattern) ? $shape : self::DEFAULT;
            $this->assertTraceIs($expectedType, $issues, $pattern);
        }
    }

    /**
     * @return iterable<string, array{phpVersion: string, options: array<string, string>, reported: array<string, string|null>, narrowed: list<string>}>
     */
    public static function provideTargets(): iterable
    {
        yield 'Psalm on PHP 8.4' => [
            'phpVersion' => '8.4',
            'options' => [],
            'reported' => [],
            'narrowed' => [self::KEEP, self::ASCII, self::NO_AUTO_CAPTURE],
        ];
        yield 'Psalm on PHP 8.5' => [
            'phpVersion' => '8.5',
            'options' => [],
            'reported' => [self::KEEP => 'PHP 8.5 with PCRE2 10.44'],
            'narrowed' => [self::ASCII, self::NO_AUTO_CAPTURE],
        ];
        yield 'Psalm on PHP 8.2' => [
            'phpVersion' => '8.2',
            'options' => [],
            'reported' => [self::ASCII => 'PHP 8.2 with PCRE2 10.40'],
            'narrowed' => [self::KEEP, self::NO_AUTO_CAPTURE],
        ];
        // Judged as 8.2, the floor; PHP 8.1 refuses the n modifier, so its pattern is not narrowed.
        yield 'Psalm on PHP 8.1, judged as 8.2' => [
            'phpVersion' => '8.1',
            'options' => [],
            'reported' => [self::ASCII => 'PHP 8.2 with PCRE2 10.40'],
            'narrowed' => [self::KEEP],
        ];
        yield 'phpVersion option over Psalm\'s' => [
            'phpVersion' => '8.4',
            'options' => ['phpVersion' => '8.5'],
            'reported' => [self::KEEP => 'PHP 8.5 with PCRE2 10.44'],
            'narrowed' => [self::ASCII, self::NO_AUTO_CAPTURE],
        ];
        yield 'phpVersion option as a PHP_VERSION_ID' => [
            'phpVersion' => '8.4',
            'options' => ['phpVersion' => '80200'],
            'reported' => [self::ASCII => 'PHP 8.2 with PCRE2 10.40'],
            'narrowed' => [self::KEEP, self::NO_AUTO_CAPTURE],
        ];
        yield 'pcreVersion option' => [
            'phpVersion' => '8.4',
            'options' => ['pcreVersion' => '10.40'],
            'reported' => [self::ASCII => 'PHP 8.4 with PCRE2 10.40'],
            'narrowed' => [self::KEEP, self::NO_AUTO_CAPTURE],
        ];
        // A major past 8 is named as such in the message.
        yield 'phpVersion option past PHP 8' => [
            'phpVersion' => '8.4',
            'options' => ['phpVersion' => '9.0'],
            'reported' => [self::KEEP => 'PHP 9.0 with PCRE2 10.44'],
            'narrowed' => [self::ASCII, self::NO_AUTO_CAPTURE],
        ];
        // 'runtime' judges with the PHP running Psalm: from PHP 8.5 it refuses \K in a lookaround.
        $refusesKeep = \PHP_VERSION_ID >= 80500;
        yield 'phpVersion option runtime over Psalm on PHP 8.5' => [
            'phpVersion' => '8.5',
            'options' => ['phpVersion' => 'runtime'],
            'reported' => $refusesKeep ? [self::KEEP => null] : [],
            'narrowed' => [...($refusesKeep ? [] : [self::KEEP]), self::ASCII, self::NO_AUTO_CAPTURE],
        ];
    }

    #[Test]
    #[DataProvider('provideUnreadableOptions')]
    public function test_plugin_refuses_an_option_it_cannot_read(string $option, string $value): void
    {
        try {
            PsalmRun::issues([self::FILE], '8.4', [$option => $value]);
            $this->fail(\sprintf('<%s>%s</%s> was accepted.', $option, $value, $option));
        } catch (ConfigException $e) {
            $this->assertInstanceOf(InvalidRegexOptionException::class, $e->getPrevious(), $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{option: string, value: string}>
     */
    public static function provideUnreadableOptions(): iterable
    {
        yield 'phpVersion not a version' => ['option' => 'phpVersion', 'value' => 'banana'];
        yield 'pcreVersion not a release' => ['option' => 'pcreVersion', 'value' => 'banana'];
    }

    /**
     * A pattern every target accepts may mean something else for each:
     * "{,3}" is a quantifier from PCRE2 10.43 (ChangeLog 10.43, the Perl 5.34
     * syntax), literal text before. preg_match('/(a{,3})/', 'aa', $m) -> 1,
     * $m = ['aa', 'aa'] (PHP 8.4.26, PCRE2 10.44 and later). Each target gets
     * its own type, whichever run comes first in the process.
     */
    #[Test]
    public function test_plugin_types_a_pattern_as_each_target_reads_it(): void
    {
        $file = 'Target/short_quantifier.php';
        $expected = [
            '8.2' => "array{0: 'a{,3}', 1: 'a{,3}'}",
            '8.4' => 'array{0: string, 1: string}',
        ];

        foreach ($expected as $phpVersion => $type) {
            $issues = PsalmRun::inFile(PsalmRun::issues([$file], $phpVersion), $file);
            $this->assertSame(['Trace'], array_map(static fn (IssueData $issue): string => $issue->type, $issues), 'PHP '.$phpVersion.': one trace, no issue.');

            $traced = PsalmTypes::parse(substr((string) $issues[0]->message, \strlen('$m: ')));
            $this->assertTrue(
                PsalmTypes::isContainedBy($traced, PsalmTypes::parse($type)) && PsalmTypes::isContainedBy(PsalmTypes::parse($type), $traced),
                \sprintf('PHP %s: expected $m = %s, Psalm traced %s.', $phpVersion, $type, $issues[0]->message),
            );
        }
    }

    /**
     * @param list<IssueData> $issues
     */
    private function assertTraceIs(string $expected, array $issues, string $pattern): void
    {
        $line = self::traceLineOf($pattern);
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && $issue->line_from === $line));
        $this->assertCount(1, $traces, \sprintf('No trace of %s on line %d.', $pattern, $line));

        $traced = PsalmTypes::parse(substr($traces[0]->message, \strlen('$m: ')));
        $type = PsalmTypes::parse($expected);
        $this->assertTrue(
            PsalmTypes::isContainedBy($traced, $type) && PsalmTypes::isContainedBy($type, $traced),
            \sprintf('%s: expected $m = %s, Psalm traced %s.', $pattern, $expected, $traces[0]->message),
        );
    }

    /**
     * The line of the trace that follows the call on the pattern.
     */
    private static function traceLineOf(string $pattern): int
    {
        $lines = file(PsalmRun::FIXTURES.'/'.self::FILE, \FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $index => $text) {
            if (str_contains($text, "'".$pattern."'")) {
                return $index + 2;
            }
        }

        throw new \LogicException($pattern.' is not in '.self::FILE);
    }
}
