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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * The plugin never replaces a stub: Psalm with the plugin finds what Psalm
 * without it finds on code that does not read $matches, plus the plugin's
 * own issues, which the fixture declares ("// InvalidRegexPattern"). Purity,
 * the falsable return, the pattern parameter's type, the templated flags and
 * the taint flows of the preg_* stubs all stay.
 *
 * preg_match_all() is typed only while this holds (PsalmPluginMatchAllTest).
 */
final class PsalmPluginParityTest extends TestCase
{
    private const FILE = 'Parity/stub_behaviour.php';

    #[Test]
    public function test_plugin_adds_only_the_issues_the_fixture_declares(): void
    {
        $with = self::described(PsalmRun::issues([self::FILE]));
        $without = self::described(PsalmRun::issues([self::FILE], '8.4', null));

        $added = array_values(array_diff($with, $without));
        $declared = array_map(static fn (int $line): string => 'InvalidRegexPattern '.$line, self::declaredLines());

        $this->assertSame($declared, array_map(static fn (string $issue): string => (string) preg_replace('~^(\S+) (\d+):\d+ .*$~s', '$1 $2', $issue), $added), implode("\n", $added));
    }

    #[Test]
    public function test_plugin_keeps_every_issue_of_the_stubs(): void
    {
        $with = self::described(PsalmRun::issues([self::FILE]));
        $without = self::described(PsalmRun::issues([self::FILE], '8.4', null));

        $this->assertNotSame([], $without, 'The fixture must exercise the stubs.');
        $this->assertSame([], array_values(array_diff($without, $with)));
    }

    #[Test]
    public function test_plugin_keeps_every_taint_of_the_stubs(): void
    {
        $with = self::tainted(PsalmRun::issues([self::FILE], '8.4', [], [], true));
        $without = self::tainted(PsalmRun::issues([self::FILE], '8.4', null, [], true));

        $this->assertNotSame([], $without, 'The fixture must exercise the stubs\' taint flows.');
        $this->assertSame($without, $with);
    }

    /**
     * "Type line:column message", sorted.
     *
     * @param list<IssueData> $issues
     *
     * @return list<string>
     */
    private static function described(array $issues): array
    {
        $described = array_map(static fn (IssueData $issue): string => \sprintf('%s %d:%d %s', $issue->type, $issue->line_from, $issue->column_from, $issue->message), $issues);
        sort($described);

        return $described;
    }

    /**
     * The taint issues, described and sorted.
     *
     * @param list<IssueData> $issues
     *
     * @return list<string>
     */
    private static function tainted(array $issues): array
    {
        return array_values(array_filter(self::described($issues), static fn (string $issue): bool => str_starts_with($issue, 'Tainted')));
    }

    /**
     * @return list<int>
     */
    private static function declaredLines(): array
    {
        $lines = [];
        foreach (file(PsalmRun::FIXTURES.'/'.self::FILE, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $text) {
            if (str_ends_with($text, '// InvalidRegexPattern')) {
                $lines[] = $index + 1;
            }
        }

        return $lines;
    }
}
