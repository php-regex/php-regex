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

namespace PHPRegex\Linter;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\RegexParser;

/**
 * What a lint run judges with: the project's target, the analysis and the
 * lint that judge for it, and what the configuration they were read from
 * holds that the run ignores.
 *
 * @phpstan-import-type OptionsArray from ParserOptions
 *
 * @internal
 */
final class LintSetup
{
    /**
     * @param list<string> $warnings one line each, said before the report
     */
    public function __construct(
        public readonly ProjectTarget $target,
        public readonly AnalysisService $analysis,
        public readonly LintService $lint,
        public readonly array $warnings = [],
    ) {}

    /**
     * The analysis and the lint of a front end, judging for the target: the
     * patterns are read with the parser its options build for the target's
     * floor, and judged over the target's range. The lint never compiles a
     * pattern with the running PHP: only the running PHP can say what it
     * compiles.
     *
     * @param OptionsArray $regexOptions what RegexParser::create() takes, but the target
     * @param list<string> $warnings     what the configuration holds that the run ignores
     */
    public static function forTarget(ProjectTarget $target, array $regexOptions, AnalysisService $analysis, LintService $lint, array $warnings = []): self
    {
        $parser = RegexParser::create($regexOptions + $target->regexOptions());
        $analysis = $analysis->withParser($parser, $target->rangeParsers($parser), $target->judgedRange($parser));

        return new self($target, $analysis, $lint->withAnalysis($analysis), $warnings);
    }
}
