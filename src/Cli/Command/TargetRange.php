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

namespace PHPRegex\Cli\Command;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\RangeFindings;
use PHPRegex\Linter\RangePoints;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;

/**
 * The PHP versions a command that reads one pattern judges it for, as the
 * command line names them: the floor, whose facade computes the command's
 * own output, and each later version of a range, at which the pattern is
 * validated as lint and the language server validate it. The parsers of
 * the later versions are built once and serve every judgement of the run.
 *
 * @internal
 *
 * @phpstan-import-type RangePoint from RangePoints
 */
final class TargetRange
{
    /**
     * @param ProjectTarget     $target the target resolved, with where it came from
     * @param Regex             $regex  the facade of the floor
     * @param list<RegexParser> $later  a parser for each version after the floor, sharing the floor's cache
     */
    public function __construct(
        public readonly ProjectTarget $target,
        public readonly Regex $regex,
        private readonly array $later,
    ) {}

    /**
     * The verdict of each version on the pattern, the floor first: the
     * "range" list of a JSON document.
     *
     * @return non-empty-list<RangePoint>
     */
    public function points(string $pattern): array
    {
        return RangePoints::of($this->regex->parser(), $this->later, $pattern);
    }

    /**
     * Whether a later version of the range refuses a pattern the floor
     * accepts, as the points say it: the floor's refusal is the command's
     * own verdict.
     *
     * @param non-empty-list<RangePoint> $points
     */
    public static function refusedLater(array $points): bool
    {
        if (!$points[0]['is_valid']) {
            return false;
        }

        foreach ($points as $point) {
            if (!$point['is_valid']) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the later versions of the range say of a pattern the floor
     * accepts, in the words of lint and the language server: the refusal
     * of the lowest that refuses it, else the lowest that reads it into
     * another tree, else the first change of meaning that keeps the tree.
     * Nothing for a pattern the floor refuses: the command's own output
     * says so.
     *
     * @return array{refusal: string|null, warning: string|null}
     */
    public function findings(string $pattern): array
    {
        // A pattern the floor accepts comes with the tree it read.
        $ast = [] === $this->later ? null : $this->regex->parser()->validate($pattern)->ast;
        if (null === $ast) {
            return ['refusal' => null, 'warning' => null];
        }

        return RangeFindings::of($this->regex->parser(), $this->later, $pattern, $ast, true)->wording();
    }
}
