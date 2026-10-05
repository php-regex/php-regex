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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Tests\Support\PublicSurface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The public surface table of docs/reference/backward-compatibility.md and the
 * classes the code treats as public are the same list: one row per package,
 * each an exhaustive list of backticked class paths relative to the package.
 */
final class BackwardCompatibilityDocTest extends TestCase
{
    private const DOC = 'docs/reference/backward-compatibility.md';

    private const TABLE_INTRO = 'In short, the public surface is:';

    private const NO_CLASS = 'no PHP class is public';

    private const RESULTS_INTRO = 'The result objects are:';

    #[Test]
    #[DataProvider('providePackages')]
    public function test_bc_table_lists_exactly_the_public_classes(string $directory): void
    {
        $label = self::labels()[$directory];
        $rows = self::rows();
        $this->assertArrayHasKey($label, $rows, \sprintf('%s has no row for `%s` (src/%s).', self::DOC, $label, $directory));

        preg_match_all('~`([^`]+)`~', $rows[$label], $matches);
        $documented = $matches[1];
        sort($documented);

        $public = array_map(static fn (string $class): string => str_replace('/', '\\', $class), PublicSurface::CLASSES[$directory]);
        sort($public);

        $this->assertSame(
            $public,
            $documented,
            \sprintf('The `%s` row of %s and the public classes of src/%s (tests/Support/PublicSurface.php) differ.', $label, self::DOC, $directory),
        );
    }

    #[Test]
    public function test_bc_table_has_a_row_per_package(): void
    {
        $rows = self::rows();
        $labels = array_values(self::labels());
        sort($labels);
        $documented = array_keys($rows);
        sort($documented);

        $this->assertSame($labels, $documented, self::DOC.': one row per package under src/, no other.');

        foreach (self::labels() as $directory => $label) {
            $cell = $rows[$label];
            if ([] === PublicSurface::CLASSES[$directory]) {
                $this->assertSame(self::NO_CLASS, $cell, \sprintf('The `%s` row of %s: src/%s has no public class.', $label, self::DOC, $directory));
            } else {
                $this->assertMatchesRegularExpression(
                    '~^`[^`]+`(?:, `[^`]+`)*$~',
                    $cell,
                    \sprintf('The `%s` row of %s lists backticked class paths only; prose goes under the table.', $label, self::DOC),
                );
            }
        }
    }

    #[Test]
    public function test_bc_doc_lists_exactly_the_result_objects(): void
    {
        $labels = self::labels();
        $expected = [];
        foreach (PublicSurface::RESULTS as $directory => $classes) {
            $this->assertArrayHasKey($directory, $labels, 'PublicSurface::RESULTS names src/'.$directory.', which has no composer.json.');
            $this->assertSame([], array_values(array_diff($classes, PublicSurface::CLASSES[$directory])), 'PublicSurface::RESULTS lists classes of src/'.$directory.' that PublicSurface::CLASSES does not: a result object is public.');
            $classes = array_map(static fn (string $class): string => str_replace('/', '\\', $class), $classes);
            sort($classes);
            $expected[$labels[$directory]] = $classes;
        }
        ksort($expected);

        $documented = self::resultList();
        ksort($documented);

        $this->assertSame(
            $expected,
            $documented,
            \sprintf('The result objects listed after "%s" in %s and PublicSurface::RESULTS (tests/Support/PublicSurface.php) differ.', self::RESULTS_INTRO, self::DOC),
        );
    }

    /**
     * @return iterable<string, array{directory: string}>
     */
    public static function providePackages(): iterable
    {
        foreach (array_keys(PublicSurface::CLASSES) as $directory) {
            yield $directory => ['directory' => $directory];
        }
    }

    /**
     * The result objects the document lists, one bullet per package
     * (`- `regex-parser`: `A`, `B`;`), keyed by the package label, each list
     * sorted. A label seen twice fails.
     *
     * @return array<string, list<string>>
     */
    private static function resultList(): array
    {
        $doc = (string) file_get_contents(self::root().'/'.self::DOC);
        $start = strpos($doc, self::RESULTS_INTRO);
        self::assertNotFalse($start, self::DOC.' has no "'.self::RESULTS_INTRO.'" list.');

        $bullets = [];
        foreach (\array_slice(explode("\n", substr($doc, $start)), 1) as $line) {
            if (str_starts_with($line, '- ')) {
                $bullets[] = substr($line, 2);
            } elseif ([] !== $bullets && str_starts_with($line, '  ')) {
                $bullets[\count($bullets) - 1] .= ' '.trim($line);
            } elseif ([] !== $bullets || '' !== trim($line)) {
                break;
            }
        }
        self::assertNotSame([], $bullets, self::DOC.': no bullet after "'.self::RESULTS_INTRO.'".');

        $list = [];
        foreach ($bullets as $bullet) {
            self::assertSame(1, preg_match('~^`([^`]+)`: (.+?)[;.]?$~', $bullet, $parts), self::DOC.': a result bullet reads "`package`: `Class`, `Class`;": '.$bullet);
            self::assertArrayNotHasKey($parts[1], $list, self::DOC.': two result bullets for `'.$parts[1].'`.');
            self::assertSame(1, preg_match('~^`[^`]+`(?:, `[^`]+`)*$~', $parts[2]), self::DOC.': the `'.$parts[1].'` result bullet lists backticked class paths only: '.$parts[2]);
            preg_match_all('~`([^`]+)`~', $parts[2], $classes);
            $names = $classes[1];
            sort($names);
            $list[$parts[1]] = $names;
        }

        return $list;
    }

    /**
     * The table's cells, keyed by the package label (`regex-parser`), in the
     * order of the document. A label seen twice fails.
     *
     * @return array<string, string>
     */
    private static function rows(): array
    {
        $doc = (string) file_get_contents(self::root().'/'.self::DOC);
        $start = strpos($doc, self::TABLE_INTRO);
        self::assertNotFalse($start, self::DOC.' has no "'.self::TABLE_INTRO.'" table.');

        $rows = [];
        $inTable = false;
        foreach (\array_slice(explode("\n", substr($doc, $start)), 1) as $line) {
            if (!str_starts_with($line, '|')) {
                if ($inTable) {
                    break;
                }

                continue;
            }
            $inTable = true;
            $cells = array_map(trim(...), explode('|', trim($line, " \t|")));
            if (2 !== \count($cells)) {
                self::fail(self::DOC.': a public surface row has two cells: '.$line);
            }
            [$label, $cell] = $cells;
            if ('package' === $label || 1 === preg_match('~^-+$~', $label)) {
                continue;
            }
            $label = trim($label, '`');
            self::assertArrayNotHasKey($label, $rows, self::DOC.': two rows for `'.$label.'`.');
            $rows[$label] = $cell;
        }

        return $rows;
    }

    /**
     * The package label of each src/<Package>, read from its composer.json
     * name (php-regex/regex-parser gives regex-parser).
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        $labels = [];
        foreach (glob(self::root().'/src/*/composer.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            $name = $data['name'] ?? null;
            self::assertIsString($name, $manifest);
            $labels[basename(\dirname($manifest))] = substr($name, \strlen('php-regex/'));
        }

        return $labels;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
