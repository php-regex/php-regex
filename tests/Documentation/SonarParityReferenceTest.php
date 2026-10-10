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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules taken from SonarPHP's regex checks are documented where a user
 * looks: the id table of docs/reference/rules.md (with their severity and,
 * for the opt-in ones, "off by default"), the mapping page that names every
 * Sonar regex rule on PHP once, and the CHANGELOG.
 */
final class SonarParityReferenceTest extends TestCase
{
    private const REFERENCE = __DIR__.'/../../docs/reference/rules.md';

    private const MAPPING = __DIR__.'/../../docs/reference/sonar.md';

    private const CHANGELOG = __DIR__.'/../../CHANGELOG.md';

    /**
     * Every SonarPHP regex rule on PHP.
     */
    private const SONAR_RULES = [
        'S5361', 'S5842', 'S5843', 'S5850', 'S5855', 'S5856', 'S5857', 'S5867', 'S5868', 'S5869',
        'S5994', 'S5996', 'S6001', 'S6002', 'S6019', 'S6035', 'S6323', 'S6326', 'S6328', 'S6331',
        'S6353', 'S6393', 'S6395', 'S6396', 'S6397',
    ];

    /**
     * The ids added for SonarPHP parity, with their severity in the id table
     * and whether they are off by default.
     */
    private const NEW_IDS = [
        'regex.lint.quantifier.emptyRepeat' => ['warning', false],
        'regex.lint.anchor.alternationPrecedence' => ['warning', false],
        'regex.lint.quantifier.possessiveImpossible' => ['warning', false],
        'regex.lint.anchor.impossible.boundary' => ['warning', false],
        'regex.lint.lookaround.impossible' => ['warning', false],
        'regex.lint.group.empty' => ['warning', false],
        'regex.lint.charclass.single' => ['style', true],
        'regex.lint.literal.multipleSpaces' => ['style', true],
        'regex.lint.quantifier.lazyToClass' => ['perf', true],
    ];

    #[Test]
    public function test_the_reference_id_table_lists_every_registered_rule(): void
    {
        $documented = self::idTable();

        $missing = [];
        foreach ((new LintRuleRegistry())->all() as $rule) {
            foreach ($rule->getRuleIds() as $id) {
                if (!isset($documented[$id])) {
                    $missing[] = $id;
                }
            }
        }

        $this->assertSame([], $missing, 'docs/reference/rules.md has no id table row for these rules.');
    }

    #[Test]
    #[DataProvider('provideNewIds')]
    public function test_the_reference_id_table_gives_a_new_rule_its_severity(string $id, string $severity, bool $offByDefault): void
    {
        $row = self::idTable()[$id] ?? null;

        $this->assertNotNull($row, \sprintf('docs/reference/rules.md has no id table row naming %s.', $id));
        $this->assertSame($severity, $row[2], \sprintf('The severity cell of %s.', $id));
        if ($offByDefault) {
            $this->assertStringContainsString('off by default', $row[1], \sprintf('%s is off by default.', $id));
        } else {
            $this->assertStringNotContainsString('off by default', $row[1], \sprintf('%s runs by default.', $id));
        }
    }

    /**
     * @return iterable<string, array{id: string, severity: string, offByDefault: bool}>
     */
    public static function provideNewIds(): iterable
    {
        foreach (self::NEW_IDS as $id => [$severity, $offByDefault]) {
            yield $id => ['id' => $id, 'severity' => $severity, 'offByDefault' => $offByDefault];
        }
    }

    #[Test]
    public function test_the_mapping_page_names_every_sonar_rule_once(): void
    {
        $this->assertFileExists(self::MAPPING);

        $rows = array_keys(self::mappingRows());
        sort($rows);
        $expected = self::SONAR_RULES;
        sort($expected);

        $this->assertSame($expected, $rows);
    }

    #[Test]
    #[DataProvider('provideMappedIds')]
    public function test_the_mapping_page_points_a_sonar_rule_at_its_phpregex_id(string $sonar, string $id): void
    {
        $row = self::mappingRows()[$sonar] ?? null;

        $this->assertNotNull($row, \sprintf('docs/reference/sonar.md has no row for %s.', $sonar));
        $this->assertStringContainsString($id, (string) $row);
    }

    /**
     * @return iterable<string, array{sonar: string, id: string}>
     */
    public static function provideMappedIds(): iterable
    {
        yield 'S5842' => ['sonar' => 'S5842', 'id' => 'regex.lint.quantifier.emptyRepeat'];
        yield 'S5850' => ['sonar' => 'S5850', 'id' => 'regex.lint.anchor.alternationPrecedence'];
        yield 'S5857' => ['sonar' => 'S5857', 'id' => 'regex.lint.quantifier.lazyToClass'];
        yield 'S5994' => ['sonar' => 'S5994', 'id' => 'regex.lint.quantifier.possessiveImpossible'];
        yield 'S5996' => ['sonar' => 'S5996', 'id' => 'regex.lint.anchor.impossible.boundary'];
        yield 'S6001' => ['sonar' => 'S6001', 'id' => 'regex.lint.backref.undefined'];
        yield 'S6002' => ['sonar' => 'S6002', 'id' => 'regex.lint.lookaround.impossible'];
        yield 'S6019' => ['sonar' => 'S6019', 'id' => 'regex.lint.quantifier.lazyEnd'];
        yield 'S6323' => ['sonar' => 'S6323', 'id' => 'regex.lint.alternation.empty'];
        yield 'S6326' => ['sonar' => 'S6326', 'id' => 'regex.lint.literal.multipleSpaces'];
        yield 'S6328' => ['sonar' => 'S6328', 'id' => 'regex.replacement.undefinedGroup'];
        yield 'S6331' => ['sonar' => 'S6331', 'id' => 'regex.lint.group.empty'];
        yield 'S6396' => ['sonar' => 'S6396', 'id' => 'regex.lint.quantifier.useless'];
        yield 'S6397' => ['sonar' => 'S6397', 'id' => 'regex.lint.charclass.single'];
    }

    #[Test]
    public function test_the_mapping_page_says_what_is_partial_out_of_scope_or_phpstan_only(): void
    {
        $rows = self::mappingRows();

        // "()" is a placeholder idiom PHPRegex does not report.
        $this->assertStringContainsString('partial', $rows['S6331'] ?? '');
        // Suggesting \p{L} for [a-zA-Z] changes what matches.
        $this->assertStringContainsString('out of scope', $rows['S5867'] ?? '');
        // The CLI lint does not see the replacement argument.
        $this->assertStringContainsString('PHPStan', $rows['S6328'] ?? '');
    }

    #[Test]
    #[DataProvider('provideNewIds')]
    public function test_the_changelog_names_a_new_rule(string $id, string $severity, bool $offByDefault): void
    {
        $changelog = (string) file_get_contents(self::CHANGELOG);
        $start = strpos($changelog, '## [2.0.0]');
        $this->assertNotFalse($start);
        $end = strpos($changelog, "\n## [", $start + 1);
        $section = false === $end ? substr($changelog, $start) : substr($changelog, $start, $end - $start);

        $lines = array_values(array_filter(explode("\n", $section), static fn (string $line): bool => str_contains($line, '`'.$id.'`')));
        $this->assertNotSame([], $lines, \sprintf('The 2.0.0 section of the CHANGELOG does not name %s.', $id));

        // The entry gives the severity and the default.
        $this->assertStringContainsString($severity, $lines[0]);
        if ($offByDefault) {
            $this->assertStringContainsString('off by default', $lines[0], \sprintf('The CHANGELOG entry of %s says it is off by default.', $id));
        }
    }

    #[Test]
    public function test_the_changelog_names_the_phpstan_replacement_identifier(): void
    {
        $this->assertStringContainsString('`regex.replacement.undefinedGroup`', (string) file_get_contents(self::CHANGELOG));
    }

    /**
     * The id table of docs/reference/rules.md: every "regex.lint.*" id a row names,
     * a short ".name" expanded against the id before it ("`regex.lint.flag.useless.s`, `.m`"),
     * with the row's cells, trimmed.
     *
     * @return array<string, list<string>>
     */
    private static function idTable(): array
    {
        $ids = [];
        foreach (explode("\n", (string) file_get_contents(self::REFERENCE)) as $line) {
            if (!str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_values(array_map(trim(...), explode('|', trim($line, " \t|"))));
            if (\count($cells) < 3) {
                continue;
            }

            preg_match_all('/`([^`]+)`/', $cells[1], $matches);
            $previous = null;
            foreach ($matches[1] as $token) {
                if (str_starts_with($token, 'regex.lint.')) {
                    $previous = $token;
                    $ids[$token] ??= $cells;
                } elseif (null !== $previous && 1 === preg_match('/^\.\w+$/', $token)) {
                    $ids[substr($previous, 0, (int) strrpos($previous, '.')).$token] ??= $cells;
                }
            }
        }

        return $ids;
    }

    /**
     * The rows of the mapping page keyed by the one Sonar id of their first
     * cell; a Sonar id heading two rows fails.
     *
     * @return array<string, string>
     */
    private static function mappingRows(): array
    {
        $rows = [];
        if (!is_file(self::MAPPING)) {
            return $rows;
        }

        foreach (explode("\n", (string) file_get_contents(self::MAPPING)) as $line) {
            if (!str_starts_with($line, '|')) {
                continue;
            }

            $cells = explode('|', trim($line, " \t|"));
            if (1 !== preg_match_all('/\bS\d{4}\b/', $cells[0], $matches)) {
                continue;
            }

            $sonar = $matches[0][0];
            self::assertArrayNotHasKey($sonar, $rows, \sprintf('%s heads two rows of docs/reference/sonar.md.', $sonar));
            $rows[$sonar] = $line;
        }

        return $rows;
    }
}
