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

namespace PHPRegex\Redos;

/**
 * Identifies a vulnerable span within the pattern for heatmap rendering.
 */
final readonly class Hotspot implements \JsonSerializable
{
    public function __construct(
        public int $start,
        public int $end,
        public RedosSeverity $severity,
        public string $pattern,
        public ?string $trigger = null,
    ) {}

    /**
     * @return array{
     *     start: int,
     *     end: int,
     *     severity: string,
     *     pattern: string,
     *     trigger: string|null,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
            'severity' => $this->severity->value,
            'pattern' => $this->pattern,
            'trigger' => $this->trigger,
        ];
    }
}
