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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * Where a Psalm release reads the condition of a preg_match() call differs:
 * up to 7.10 it reads the assertions the plugin sets on the call, from 7.11
 * on only its cache of assertions, which it keeps nowhere in the body of a
 * switch. The fixtures of ConditionReadByPsalm hold on the former, those of
 * ConditionReadFromCache on the latter: the same code, checked for what each
 * reads of it. Psalm with the plugin, on PHP 8.4, reads those of the
 * installed release.
 */
final class PsalmPluginConditionCacheTest extends TestCase
{
    private const READ_BY_PSALM = 'ConditionReadByPsalm';

    private const READ_FROM_CACHE = 'ConditionReadFromCache';

    #[Test]
    #[DataProvider('provideTypeChecks')]
    public function test_condition_cache_types_as_the_fixture_of_the_installed_psalm_checks(string $file, int $from, int $to, string $variable, string $expected): void
    {
        $issues = array_values(array_filter(
            PsalmRun::inFile(self::issues(), $file),
            static fn (IssueData $issue): bool => $issue->line_from >= $from && $issue->line_from <= $to + 1,
        ));
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && str_starts_with($issue->message, $variable.': ')));
        $failures = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' !== $issue->type));

        $this->assertNotSame([], $traces, \sprintf('%s:%d: Psalm never reached the check of %s.', $file, $from, $variable));
        $this->assertSame([], array_map(PsalmRun::describe(...), $failures), \sprintf('%s:%d expects %s = %s; Psalm traced %s.', $file, $from, $variable, $expected, $traces[0]->message));
    }

    #[Test]
    public function test_condition_cache_fixtures_raise_no_other_issue(): void
    {
        $others = array_values(array_filter(self::issues(), static fn (IssueData $issue): bool => !\in_array($issue->type, ['Trace', 'CheckType'], true)));

        $this->assertSame([], array_map(static fn (IssueData $issue): string => basename($issue->file_path).' '.PsalmRun::describe($issue), $others));
    }

    /**
     * Each release is checked on the same code: the two directories hold the
     * same files, which differ in their namespace and their comments only.
     */
    #[Test]
    public function test_condition_cache_fixtures_of_either_release_hold_the_same_code(): void
    {
        $byPsalm = array_map(basename(...), PsalmRun::filesIn(self::READ_BY_PSALM));
        $fromCache = array_map(basename(...), PsalmRun::filesIn(self::READ_FROM_CACHE));

        $this->assertNotSame([], $byPsalm);
        $this->assertSame($byPsalm, $fromCache);
        foreach ($byPsalm as $file) {
            $this->assertSame(self::code(self::READ_BY_PSALM.'/'.$file), self::code(self::READ_FROM_CACHE.'/'.$file), $file);
        }
    }

    /**
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string}>
     */
    public static function provideTypeChecks(): iterable
    {
        return PsalmRun::typeChecks(self::files());
    }

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        return PsalmRun::filesIn(PsalmRun::readsPregMatchConditionFromCacheOnly() ? self::READ_FROM_CACHE : self::READ_BY_PSALM);
    }

    /**
     * @return list<IssueData>
     */
    private static function issues(): array
    {
        return PsalmRun::issues(self::files());
    }

    /**
     * The tokens of a fixture but its whitespace, its comments and its
     * namespace declaration.
     *
     * @return list<string>
     */
    private static function code(string $file): array
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents(PsalmRun::FIXTURES.'/'.$file));
        $code = [];
        $inNamespace = false;
        foreach ($tokens as $token) {
            if ($token->is(\T_NAMESPACE)) {
                $inNamespace = true;
            } elseif ($inNamespace) {
                $inNamespace = ';' !== $token->text;
            } elseif (!$token->isIgnorable()) {
                $code[] = $token->text;
            }
        }

        return $code;
    }
}
