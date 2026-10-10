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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search cost in the reference: the lint id table has a row for
 * regex.lint.redos.search, naming the PHPStan identifier, a warning that is
 * never an error; the ReDoS guide names the id.
 */
final class RedosSearchCostReferenceTest extends TestCase
{
    private const REFERENCE = __DIR__.'/../../docs/reference/rules.md';

    private const GUIDE = __DIR__.'/../../docs/guides/redos.md';

    #[Test]
    public function test_reference_id_table_has_a_row_for_the_search_cost(): void
    {
        $row = self::idTableRow('`regex.lint.redos.search`');

        $this->assertNotNull($row, 'docs/reference/rules.md has no id table row naming `regex.lint.redos.search`.');
        $this->assertCount(4, $row, 'An id table row has four cells: category, ids, severity, fix.');
        $this->assertStringContainsString('`regex.redos.search`', $row[1], 'The row names the PHPStan identifier.');
        $this->assertStringContainsString('warning', $row[2]);
        $this->assertStringNotContainsString('error', $row[2], 'A search cost is never an error.');
    }

    #[Test]
    public function test_redos_guide_names_the_search_cost_issue(): void
    {
        $guide = (string) file_get_contents(self::GUIDE);

        $this->assertStringContainsString('`regex.lint.redos.search`', $guide);
        $this->assertStringContainsString('`regex.redos.search`', $guide);
    }

    /**
     * The cells of the first table row of docs/reference/rules.md that holds
     * $needle, trimmed; null when none does.
     *
     * @return list<string>|null
     */
    private static function idTableRow(string $needle): ?array
    {
        foreach (explode("\n", (string) file_get_contents(self::REFERENCE)) as $line) {
            if (str_starts_with($line, '|') && str_contains($line, $needle)) {
                return array_values(array_map(trim(...), explode('|', trim($line, " \t|"))));
            }
        }

        return null;
    }
}
