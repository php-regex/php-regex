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
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\RegexParser;

/**
 * The verdict of each version of a range on one pattern, the floor first,
 * as a command judging one pattern reports it: whether the version accepts
 * the pattern, its error when it does not, and whether it reads the
 * pattern into another tree than the floor. A version that refuses the
 * pattern, or follows a floor that does, reads no tree to compare.
 *
 * Where RangeDiff stops at the first finding the lint reports, and compares
 * the trees only when every version accepts the pattern, each point here
 * is judged on its own: a version that accepts the pattern says whether it
 * reads it otherwise even when another version refuses it.
 *
 * @internal
 *
 * @phpstan-type RangePoint array{php: string, pcre: string, is_valid: bool, error: string|null, error_code: string|null, parses_differently: bool}
 */
final class RangePoints
{
    private function __construct() {}

    /**
     * @param RegexParser       $floor the parser of the floor
     * @param list<RegexParser> $range a parser for each version after the floor, in ascending order
     *
     * @return non-empty-list<RangePoint>
     */
    public static function of(RegexParser $floor, array $range, string $pattern): array
    {
        $floorTree = null;
        $points = [];
        foreach ([$floor, ...$range] as $index => $parser) {
            $validation = $parser->validate($pattern);
            $differs = false;
            if ($validation->isValid) {
                $tree = self::tree($parser, $pattern);
                if (0 === $index) {
                    $floorTree = $tree;
                } elseif (null !== $floorTree && null !== $tree) {
                    $differs = $tree !== $floorTree;
                }
            }

            $points[] = [
                'php' => ProjectTarget::phpLabel($parser->target()->phpVersionId),
                'pcre' => $parser->target()->pcreVersion,
                'is_valid' => $validation->isValid,
                'error' => $validation->isValid ? null : $validation->error,
                'error_code' => $validation->isValid ? null : $validation->errorCode?->value,
                'parses_differently' => $differs,
            ];
        }

        return $points;
    }

    /**
     * The tree a version that accepts the pattern reads it into, as text;
     * null when it cannot read one.
     */
    private static function tree(RegexParser $parser, string $pattern): ?string
    {
        try {
            return $parser->parse($pattern)->accept(new NodeDumper());
        } catch (LexerException|LimitException|ParserException) {
            return null;
        }
    }
}
