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

use PHPRegex\Redos\Internal\Backtrack\WitnessRenderer;

/**
 * The input that drives a match attempt into its worst case: the prefix,
 * the pump repeated n times, then the suffix. The parts hold raw bytes;
 * render() and toArray() give them as PHP double-quoted literals, with
 * every character outside printable ASCII escaped.
 *
 * @api
 *
 * @phpstan-type WitnessArray array{prefix: string, pump: string, suffix: string}
 */
final readonly class RedosWitness
{
    /**
     * @internal built by RedosAnalyzer::analyze(), for RedosAnalysis::$witness
     */
    public function __construct(
        public string $prefix,
        public string $pump,
        public string $suffix,
        public bool $unicode,
    ) {}

    /**
     * The raw input: the prefix, the pump repeated, then the suffix.
     */
    public function build(int $repetitions): string
    {
        return $this->prefix.str_repeat($this->pump, max(0, $repetitions)).$this->suffix;
    }

    /**
     * The canonical form, e.g. "aaa" . "a" x n . "!"; an empty prefix or
     * suffix is left out.
     */
    public function render(): string
    {
        $parts = [];
        if ('' !== $this->prefix) {
            $parts[] = '"'.WitnessRenderer::literal($this->prefix, $this->unicode).'"';
        }

        $parts[] = '"'.WitnessRenderer::literal($this->pump, $this->unicode).'" x n';
        if ('' !== $this->suffix) {
            $parts[] = '"'.WitnessRenderer::literal($this->suffix, $this->unicode).'"';
        }

        return implode(' . ', $parts);
    }

    /**
     * Each part as the inside of a PHP double-quoted literal.
     *
     * @return WitnessArray
     */
    public function toArray(): array
    {
        return [
            'prefix' => WitnessRenderer::literal($this->prefix, $this->unicode),
            'pump' => WitnessRenderer::literal($this->pump, $this->unicode),
            'suffix' => WitnessRenderer::literal($this->suffix, $this->unicode),
        ];
    }
}
