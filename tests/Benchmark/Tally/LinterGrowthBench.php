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

namespace PHPRegex\Tests\Benchmark\Tally;

use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Linter\Config\ComposerConstraint;
use PHPRegex\Linter\Extraction\StringExpressionReader;
use PHPRegex\Linter\Extraction\TargetMarkers;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\TwigTemplatePatternSource;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Tally\Support\Ladders;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Quadratic;
use Tally\Attribute\Warmup;
use Tally\Meter;
use Tally\ScratchDirectory;

/**
 * What the linter reads around the patterns: the sources it scans, the
 * markers and constraints it resolves, and the rules it runs on a tree.
 * Building the input is not counted; reading it is.
 */
#[Warmup(1)]
final class LinterGrowthBench
{
    use Ladders;

    /**
     * The rules that still read a sequence again for each quantifier.
     */
    private const NESTING_RULES = ['quantifier.nested', 'dotstar.nested'];

    public function __construct(private readonly Meter $meter, private readonly ScratchDirectory $scratch) {}

    /**
     * A range named with exclusions, read in one pass over them, not once
     * per piece they leave.
     */
    #[DataProvider('from250')]
    #[Linear('size')]
    public function benchComposerExclusions(int $size): void
    {
        $constraint = $this->meter->exclude(static function () use ($size): string {
            $constraint = '>=8.0';
            for ($index = 0; $index < $size; $index++) {
                $constraint .= ' !=8.'.intdiv($index, 100).'.'.($index % 100);
            }

            return $constraint;
        });

        ComposerConstraint::intervals($constraint, strict: true);
    }

    /**
     * Validation rule literals in a comment, each one looked up in the
     * whole file again for its syntax and its line.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchEmptyValidationRules(int $size): void
    {
        $project = $this->meter->exclude(function () use ($size): string {
            $project = $this->scratch->path().'/rules-'.$size;
            if (!is_dir($project)) {
                mkdir($project);
            }
            file_put_contents($project.'/Rules.php', "<?php\n/*\n".str_repeat("'regex:' \"not_regex:\"\n", $size)."*/\n");

            return Path::fromHost((string) realpath($project));
        });

        (new ValidationRulePatternSource())->extract(new PatternSourceContext([$project], []));
    }

    /**
     * Parentheses around a string expression, stripped one pair at a time
     * by copying the tokens inside.
     *
     * @return array{0: array<int, array{int, string, int}|string>, 1: array<int, int>}
     */
    #[DataProvider('from2000')]
    #[Linear('size')]
    public function benchNestedParentheses(int $size): array
    {
        $tokens = $this->meter->exclude(static fn (): array => array_merge(array_fill(0, $size, '('), ['a'], array_fill(0, $size, ')')));

        // Tally ignores what a benchmark returns; returning it keeps the call.
        return StringExpressionReader::stripParentheses($tokens, array_keys($tokens));
    }

    /**
     * The line and column of each pattern of a template, counted from the
     * start of the template for each one.
     */
    #[DataProvider('from500')]
    #[Linear('size')]
    public function benchTwigLocations(int $size): void
    {
        $template = $this->meter->exclude(static fn (): string => "\u{e9}\r\n{# comment\ncontinued #}{% verbatim %}\nraw{% endverbatim %}\n".str_repeat("\u{e9}{{ x matches '/a/' }}{{ y matches '/b/' }}\r\n", $size));
        TwigTemplatePatternSource::read($template, 'many.twig');
    }

    /**
     * Target markers on adjacent comments, each one scanning the rest of
     * the file for the end of its statement.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchAdjacentTargetMarkers(int $size): void
    {
        $content = $this->meter->exclude(static fn (): string => "<?php\n".str_repeat("/** ordinary comment */\n/** @regex-target php>=8.4 */\n", $size)."f();\n");
        TargetMarkers::read($content);
    }

    /**
     * Target markers on nested bodies, each one rescanned for every marker.
     */
    #[DataProvider('from500')]
    #[Linear('size')]
    public function benchNestedTargetMarkers(int $size): void
    {
        $content = $this->meter->exclude(static fn (): string => "<?php\n".str_repeat("/** @regex-target php>=8.4 */\nif (true) {\n", $size)."f();\n".str_repeat("}\n", $size));
        TargetMarkers::read($content);
    }

    /**
     * Every lint rule but the two below on optional groups: each group
     * asked whether its pattern may match nothing, and what follows it.
     * 4 000 took about 17 s when each read the pattern again.
     */
    #[DataProvider('from500')]
    #[Linear('size')]
    public function benchRulesOnOptionalGroups(int $size): void
    {
        $tree = $this->meter->exclude(static fn (): RegexNode => ColdStart::regex()->parse('/'.str_repeat('(a)?', $size).'/'));
        $tree->accept(new PatternLinter(self::rules(false)));
    }

    /**
     * Every lint rule but the two below on lazy quantifiers, each one once
     * reading the rest of its sequence again: about 6 s for 2 000.
     */
    #[DataProvider('from500')]
    #[Linear('size')]
    public function benchRulesOnLazyQuantifiers(int $size): void
    {
        $tree = $this->meter->exclude(static fn (): RegexNode => ColdStart::regex()->parse('/'.str_repeat('a*?', $size).'/'));
        $tree->accept(new PatternLinter(self::rules(false)));
    }

    /**
     * The rules on nested quantifiers and nested dot-stars still read the
     * sequence again for each quantifier: the run grows as the square of
     * the quantifiers, and the class keeps it from growing any faster. Once
     * they read it once, the class becomes #[Linear] and the rules join the
     * two benchmarks above.
     */
    #[DataProvider('from500')]
    #[Quadratic('size')]
    public function benchNestingRulesOnLazyQuantifiers(int $size): void
    {
        $tree = $this->meter->exclude(static fn (): RegexNode => ColdStart::regex()->parse('/'.str_repeat('a*?', $size).'/'));
        $tree->accept(new PatternLinter(self::rules(true)));
    }

    /**
     * Every lint rule on, or off, as the rules on nesting are on.
     *
     * @return array<string, bool>
     */
    private static function rules(bool $nesting): array
    {
        $rules = [];
        foreach (RuleCatalog::lintRuleIds() as $id) {
            $rules[$id] = $nesting === \in_array($id, self::NESTING_RULES, true);
        }

        return $rules;
    }
}
