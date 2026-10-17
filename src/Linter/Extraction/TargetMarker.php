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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Linter\Config\TargetConstraint;

/**
 * One "@regex-target" or "@regex-target-next-line" of a PHP source: the
 * line it is written on, the lines it covers, and the versions it names,
 * or why it names none.
 *
 * @internal
 */
final class TargetMarker
{
    /**
     * @param string                $marker     "@regex-target" or "@regex-target-next-line"
     * @param int                   $line       the line the marker is written on, one-based
     * @param int                   $fromLine   the first line it covers
     * @param int                   $toLine     the last line it covers
     * @param string                $text       what follows the marker on its line
     * @param TargetConstraint|null $constraint the versions it names, null when $error says why it names none
     * @param string|null           $error      why the text is no constraint
     * @param int                   $offset     where the marker starts in the source, in bytes
     * @param int                   $column     where it starts on its line, one-based, in bytes
     */
    public function __construct(
        public readonly string $marker,
        public readonly int $line,
        public readonly int $fromLine,
        public readonly int $toLine,
        public readonly string $text,
        public readonly ?TargetConstraint $constraint,
        public readonly ?string $error,
        public readonly int $offset,
        public readonly int $column = 1,
    ) {}

    public function covers(int $line): bool
    {
        return $line >= $this->fromLine && $line <= $this->toLine;
    }

    /**
     * The constraint of the markers that cover a line, all of them at once;
     * null when none that names a constraint covers it.
     *
     * @param list<self> $markers
     */
    public static function constraintAt(array $markers, int $line): ?TargetConstraint
    {
        $constraint = null;
        foreach ($markers as $marker) {
            if (null !== $marker->constraint && $marker->covers($line)) {
                $constraint = $constraint?->intersect($marker->constraint) ?? $marker->constraint;
            }
        }

        return $constraint;
    }
}
