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
    public function test_dense_empty_rules_are_scanned_in_linear_time(): void
    {
        // These produce no patterns, but every literal reaches syntax and line lookup.
        $projects = [];
        foreach ([5_000, 20_000] as $rows) {
            $projects[$rows] = $this->makeProject(['Rules.php' => "<?php\n\$rules = [\n".str_repeat("'regex:', \"not_regex:\",\n", $rows)."];\n"]);
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
     * A file PHP warns about as it reads it, an octal escape above \377 in
     * a double-quoted literal, is read without a word on the output: the
     * warning would land in the middle of a report printed there.
     */
    #[Test]
    public function test_a_file_php_warns_about_is_read_without_a_word(): void
    {
        $project = $this->makeProject(['Rules.php' => "<?php\n\$byte = \"\\600\";\n\$rules = ['a' => 'regex:/^a$/', 'b' => ('regex:/^b$/')];\nreturn \$rules;\n"]);
        $display = (string) \ini_get('display_errors');
        \ini_set('display_errors', '1');
        ob_start();

        try {
            $rules = require $project.'/Rules.php';
            $warning = (string) ob_get_contents();
            ob_clean();
            $source = new ValidationRulePatternSource();
            $occurrences = $source->extract(new PatternSourceContext([$project], []));
        } finally {
            $output = (string) ob_get_clean();
            \ini_set('display_errors', $display);
        }

        $this->assertSame(['a' => 'regex:/^a$/', 'b' => 'regex:/^b$/'], $rules);
        $this->assertStringContainsString('Octal escape sequence overflow \600 is greater than \377', $warning);
        $this->assertSame('', $output);
        $this->assertSame([[3, '/^a$/'], [3, '/^b$/']], array_map(static fn (PatternOccurrence $p): array => [$p->line, $p->pattern], $occurrences));
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
        yield 'a literal opening the code' => ['code' => "<?php 'regex:/k|l/'", 'expected' => [[1, '/k|l/']]];
        yield 'an assignment opening code that ends on an operator' => ['code' => "<?php ='regex:/k|l/';\n\$y !=", 'expected' => [[1, '/k']]];
        yield 'a push opening the code' => ['code' => "<?php []='regex:/k|l/'", 'expected' => [[1, '/k|l/']]];
        yield 'an assignment opening code that ends on brackets' => ['code' => "<?php ='regex:/k|l/';[]", 'expected' => [[1, '/k']]];
        yield 'an arrow opening the code' => ['code' => "<?php =>'regex:/k|l/'", 'expected' => [[1, '/k']]];
        yield 'rules without a regex one' => ['code' => "<?php\n\$r=['a'=>'required|min:2'];\n", 'expected' => []];
    }

    /**
     * The excludes leave a file out of the rules read as they leave it out
     * of the PHP files: a directory name below the project, the path of a
     * file or a directory, from the project or the working directory,
     * behind "./" or absolute; a directory above the project excludes
     * nothing.
     *
     * @param list<string> $expected the files read, from the project
     */
    #[Test]
    #[DataProvider('provideExcludes')]
    public function test_the_excludes_leave_out_a_file_as_they_leave_out_a_php_file(string $exclude, bool $fromProject, array $expected): void
    {
        $root = $this->enterProject([
            'app/Http/Requests/StoreRequest.php' => "<?php\n\$rules = ['a' => 'regex:/^a$/'];\n",
            'app/Legacy/OldRequest.php' => "<?php\n\$rules = ['a' => 'regex:/^b$/'];\n",
        ]);
        $exclude = str_replace(['{root}', '{above}'], [$root, basename(\dirname($root))], $exclude);

        $occurrences = (new ValidationRulePatternSource())->extract(new PatternSourceContext([$root.'/app'], [$exclude], excludeBase: $fromProject ? $root : null));

        $files = array_map(static fn (PatternOccurrence $occurrence): string => substr($occurrence->file, \strlen($root) + 1), $occurrences);
        sort($files);
        $this->assertSame($expected, $files);
    }

    /**
     * @return iterable<string, array{exclude: string, fromProject: bool, expected: list<string>}>
     */
    public static function provideExcludes(): iterable
    {
        $kept = ['app/Http/Requests/StoreRequest.php'];

        yield 'a directory name' => ['exclude' => 'Legacy', 'fromProject' => true, 'expected' => $kept];
        yield 'a file, from the project' => ['exclude' => 'app/Legacy/OldRequest.php', 'fromProject' => true, 'expected' => $kept];
        yield 'a directory behind ./, from the project' => ['exclude' => './app/Legacy', 'fromProject' => true, 'expected' => $kept];
        yield 'a file behind ./, from the working directory' => ['exclude' => './app/Legacy/OldRequest.php', 'fromProject' => false, 'expected' => $kept];
        yield 'a directory by its absolute path' => ['exclude' => '{root}/app/Legacy', 'fromProject' => false, 'expected' => $kept];
        yield 'a directory above the project' => ['exclude' => '{above}', 'fromProject' => true, 'expected' => ['app/Http/Requests/StoreRequest.php', 'app/Legacy/OldRequest.php']];
    }
}
