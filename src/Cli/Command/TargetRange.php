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
use PHPRegex\Linter\RangeWording;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;

/**
 * The PHP versions a command that reads one pattern judges it for, found
 * where lint finds them: the floor, whose facade computes the command's
 * own output, and each later version of the range, at which the pattern
 * is validated as lint and the language server validate it.
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
     * Whether a version of the range refuses the pattern, the floor
     * included, as the points say it.
     *
     * @param non-empty-list<RangePoint> $points
     */
    public static function refused(array $points): bool
    {
        foreach ($points as $point) {
            if (!$point['is_valid']) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the versions judged say of the pattern, in the words of lint
     * and the language server: the refusal of the floor, else the refusal
     * of the lowest later version that refuses it, else the lowest that
     * reads it into another tree, else the first change of meaning that
     * keeps the tree.
     *
     * @return array{refusal: string|null, warning: string|null}
     */
    public function findings(string $pattern): array
    {
        $none = ['refusal' => null, 'warning' => null];
        $floor = $this->regex->parser();
        $validation = $floor->validate($pattern);
        if (!$validation->isValid) {
            return ['refusal' => RangeWording::refusal('PHP '.ProjectTarget::phpLabel($floor->target()->phpVersionId), $validation), 'warning' => null];
        }

        if ([] === $this->later) {
            return $none;
        }

        try {
            $ast = $floor->parse($pattern);
        } catch (LexerException|LimitException|ParserException) {
            return $none;
        }

        $findings = RangeFindings::of($floor, $this->later, $pattern, $ast, true);
        if (null !== $findings->refusedBy) {
            return ['refusal' => RangeWording::refusal($findings->refusedOn, $findings->refusedBy->validation), 'warning' => null];
        }

        if (null !== $findings->readsOtherwise) {
            return ['refusal' => null, 'warning' => RangeWording::readsOtherwise($findings->readsOtherwise)];
        }

        if (null !== $findings->silentChange) {
            return ['refusal' => null, 'warning' => RangeWording::silentChange($findings->silentChange->target, $findings->silentChange->message)];
        }

        return $none;
    }
}
