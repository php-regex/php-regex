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

namespace PHPRegex\Parser\Internal;

use PHPRegex\Parser\Analysis\CaptureGroupShape;

/**
 * One key preg_match() or preg_match_all() writes into $matches, and what
 * its value may be: the value of one of $groups when set, what an unset
 * group reads ('' or null) when $unset, a mark name when $marks.
 *
 * A key with no group and no unset value is the marks' own "MARK" key: it
 * holds a mark name, never a group's value nor an offset pair.
 *
 * @internal
 */
final readonly class CaptureKey
{
    /**
     * @param int|string              $key       the group number, 0 for the whole match, or the name
     * @param bool                    $optional  some match leaves the key out
     * @param list<CaptureGroupShape> $groups    the groups whose value the key may hold, none that no match sets
     * @param bool                    $unset     the key may hold what an unset group reads
     * @param bool                    $marks     the key may hold the name a mark verb leaves
     * @param bool                    $alwaysSet the value is a set group's on every match: an offset pair then starts at 0, not -1
     */
    public function __construct(
        public int|string $key,
        public bool $optional,
        public array $groups,
        public bool $unset,
        public bool $marks,
        public bool $alwaysSet,
    ) {}

    /**
     * The marks' own key, as opposed to the key of a group named MARK the
     * marks share: a mark name, never a group's value nor an offset pair.
     */
    public function holdsMarksOnly(): bool
    {
        return $this->marks && [] === $this->groups && !$this->unset;
    }
}
