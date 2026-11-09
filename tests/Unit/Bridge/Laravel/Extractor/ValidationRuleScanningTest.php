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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Extractor;

use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationRuleScanningTest extends TestCase
{
    use LinearTimeAssertions;
    use TemporaryProject;

    #[Test]
    public function test_dense_empty_rules_in_comments_are_scanned_in_linear_time(): void
    {
        // These produce no patterns, but every literal reaches syntax and line lookup.
        $projects = [];
        foreach ([5_000, 20_000] as $rows) {
            $projects[$rows] = $this->makeProject(['Rules.php' => "<?php\n/*\n".str_repeat("'regex:' \"not_regex:\"\n", $rows)."*/\n"]);
        }

        $this->assertLinearTime(function (int $rows) use ($projects): void {
            $source = new ValidationRulePatternSource();
            $this->assertSame([], $source->extract(new PatternSourceContext([$projects[$rows]], [])));
            $this->assertSame([], $source->failures());
        }, 5_000, 'Empty validation rules');
    }

    #[Test]
    public function test_syntax_classification_and_lines_survive_whitespace_and_adjacent_matches(): void
    {
        $project = $this->makeProject(['Rules.php' => <<<'PHP'
            <?php
            $rules = [
                'a' =>
                    'regex:/a|b/', 'regex:/c|d/',
            ];
            $rules['a'] .= 'regex:/e|f/';
            $rules['b'] ??= 'regex:/g|h/';
            $rules['c'][]
                =
                    'regex:/i|j/';
            $x == 'regex:/k|l/';
            $x != 'regex:/m|n/';
            $x <= 'regex:/o|p/';
            $x >= 'regex:/q|r/';
            'nullable|regex:/s|t/';
            PHP]);
        $source = new ValidationRulePatternSource();
        $patterns = $source->extract(new PatternSourceContext([$project], []));

        $this->assertSame([
            [4, '/a'], [4, '/c|d/'], [6, '/e'], [7, '/g'],
            [10, '/i|j/'], [11, '/k|l/'], [12, '/m|n/'],
            [13, '/o|p/'], [14, '/q|r/'], [15, '/s'],
        ], array_map(static fn (PatternOccurrence $p): array => [$p->line, $p->pattern], $patterns));
        $this->assertSame([], $source->failures());
    }

    /**
     * @param list<array{int, string}> $expected
     */
    #[Test]
    #[DataProvider('provideTightSpellings')]
    public function test_rules_written_without_spaces_or_at_the_start_of_the_file(string $code, array $expected): void
    {
        $project = $this->makeProject(['Rules.php' => $code]);
        $source = new ValidationRulePatternSource();

        $this->assertSame($expected, array_map(static fn (PatternOccurrence $p): array => [$p->line, $p->pattern], $source->extract(new PatternSourceContext([$project], []))));
        $this->assertSame([], $source->failures());
    }

    /**
     * @return iterable<string, array{code: string, expected: list<array{int, string}>}>
     */
    public static function provideTightSpellings(): iterable
    {
        yield 'a newline before the open tag' => ['code' => "\n<?php\n\$r = ['a'=>'regex:/a|b/'];\n", 'expected' => [[3, '/a']]];
        yield 'a comparison without spaces' => ['code' => "<?php\n\$x=='regex:/k|l/';\n", 'expected' => [[2, '/k|l/']]];
        yield 'an assignment without spaces' => ['code' => "<?php\n\$x='regex:/k|l/';\n", 'expected' => [[2, '/k']]];
        yield 'a push without spaces' => ['code' => "<?php\n\$x[]='regex:/k|l/';\n", 'expected' => [[2, '/k|l/']]];
        yield 'a literal opening the file' => ['code' => "'regex:/k|l/'", 'expected' => [[1, '/k|l/']]];
        yield 'an assignment opening a file that ends on an operator' => ['code' => "='regex:/k|l/';\n\$y !=", 'expected' => [[1, '/k']]];
        yield 'a push opening the file' => ['code' => "[]='regex:/k|l/'", 'expected' => [[1, '/k|l/']]];
        yield 'an assignment opening a file that ends on brackets' => ['code' => "='regex:/k|l/';[]", 'expected' => [[1, '/k']]];
        yield 'an arrow opening the file' => ['code' => "=>'regex:/k|l/'", 'expected' => [[1, '/k']]];
    }
}
