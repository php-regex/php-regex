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

namespace PHPRegex\Tests\Support;

use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Resolution\ArgumentState;
use PHPUnit\Framework\Assert;

/**
 * The pattern arguments of a run, built for a test: so many in each state,
 * each at a place of its own, and the count of each state as a report
 * gives it.
 *
 * The counts are read where they are a contract, in the stats of the JSON
 * report, under "arguments_" and the state.
 *
 * @phpstan-import-type LintStats from LintReport
 */
final class ArgumentCarriers
{
    /**
     * The line of the summary the counts below give: 5805 arguments no
     * rule ran on.
     */
    public const LINE = 'Not verifiable: 5805 pattern arguments (2168 dynamic, 2416 around a dynamic part, 1221 around preg_quote())';

    /**
     * Every state, three of them not verified: the two that are do not
     * count in the line.
     */
    public const COUNTS = ['constant' => 7, 'finite_set' => 3, 'quoted_shell' => 1221, 'raw_shell' => 2416, 'opaque' => 2168];

    /**
     * So many arguments in each state, one per line of the file, in the
     * order of the states given.
     *
     * @param array<string, int> $counts the state, a value of ArgumentState, and how many arguments are in it
     *
     * @return list<PatternOccurrence>
     */
    public static function of(array $counts, string $file = 'src/a.php'): array
    {
        $carriers = [];
        $line = 0;
        foreach ($counts as $state => $count) {
            Assert::assertNotNull(ArgumentState::tryFrom($state), '"'.$state.'" is no state of an argument.');
            for ($i = 0; $i < $count; $i++) {
                $line++;
                $carriers[] = PatternOccurrence::argument($state, $file, $line, 12, 40 * $line, 'preg_match()', '$pattern'.$line);
            }
        }

        return $carriers;
    }

    /**
     * The count of each state as the JSON report writes the stats, by
     * state, in the order of ArgumentState; a state the report does not
     * count is left out.
     *
     * @param array<string, int> $stats
     *
     * @phpstan-param LintStats $stats
     *
     * @return array<string, int>
     */
    public static function counts(array $stats): array
    {
        $document = json_decode((new JsonFormatter())->format(new LintReport([], $stats)), true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($document);

        return self::countsOf($document['stats'] ?? null);
    }

    /**
     * The same, of the stats of a decoded JSON report.
     *
     * @return array<string, int>
     */
    public static function countsOf(mixed $jsonStats): array
    {
        Assert::assertIsArray($jsonStats);

        $counts = [];
        foreach (ArgumentState::cases() as $state) {
            $key = 'arguments_'.$state->value;
            if (\array_key_exists($key, $jsonStats)) {
                Assert::assertIsInt($jsonStats[$key], $key);
                $counts[$state->value] = $jsonStats[$key];
            }
        }

        return $counts;
    }

    /**
     * No argument in any state.
     *
     * @return array<string, int>
     */
    public static function none(): array
    {
        return ['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 0];
    }
}
