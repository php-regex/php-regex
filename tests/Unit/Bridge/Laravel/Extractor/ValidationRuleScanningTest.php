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
use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationRuleScanningTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_dense_empty_rules_in_comments_have_a_bounded_scanning_cost(): void
    {
        // These produce no patterns, but every literal reaches syntax and line lookup.
        $project = $this->makeProject(['Rules.php' => "<?php\n/*\n".str_repeat("'regex:' \"not_regex:\"\n", 100_000)."*/\n"]);
        $source = new ValidationRulePatternSource();
        $start = CpuClock::seconds();
        $patterns = $source->extract(new PatternSourceContext([$project], []));
        $elapsed = CpuClock::seconds() - $start;

        $this->assertSame([], $patterns);
        $this->assertSame([], $source->failures());
        $this->assertLessThan(2.0, $elapsed, 'Empty rules must not repeatedly scan the file prefix.');
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
}
