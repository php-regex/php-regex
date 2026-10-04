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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\RepetitionHir;
use PHPRegex\Parser\Node\RegexNode;

/**
 * The strings every match of a pattern holds: a subject that lacks one of
 * them cannot match, so a str_contains() guard may turn it away before the
 * engine runs, as RE2's prefilter does.
 *
 * Unlike LiteralExtractor, which collects what a match may start with, this
 * keeps only what every match must hold: "/foobar|foobaz/" requires "fooba".
 *
 *     (new RequiredLiteralAnalyzer())->analyze($parser->parse('/foo\d+bar/')); // ['foo', 'bar']
 */
final class RequiredLiteralAnalyzer
{
    private bool $unicode = false;

    /**
     * @return list<string> the strings, longest runs first in pattern order,
     *                      none inside another
     */
    public function analyze(RegexNode $regex): array
    {
        $this->unicode = $regex->isUnicode();

        return self::maximal($this->required((new HirTranslator())->translate($regex)));
    }

    /**
     * @return list<string>
     */
    private function required(Hir $hir): array
    {
        $properties = $hir->properties;
        if (null !== $properties->literal) {
            return [$this->text($properties->literal)];
        }

        $required = match (true) {
            $hir instanceof ConcatHir => $this->concat($hir),
            $hir instanceof AlternationHir => $this->shared($hir),
            $hir instanceof RepetitionHir => $hir->min > 0 ? $this->required($hir->body) : [],
            $hir instanceof CaptureHir => $this->required($hir->body),
            default => [],
        };

        return [...$required, $this->text($properties->prefix), $this->text($properties->suffix)];
    }

    /**
     * A run of exact text grows with what the part before it always ends
     * with and what the part after it always starts with.
     *
     * @return list<string>
     */
    private function concat(ConcatHir $hir): array
    {
        $required = [];
        $run = '';
        foreach ($hir->parts as $part) {
            $literal = $part->properties->literal;
            if (null !== $literal) {
                $run .= $this->text($literal);

                continue;
            }

            $required[] = $run.$this->text($part->properties->prefix);
            $required = [...$required, ...$this->required($part)];
            $run = $this->text($part->properties->suffix);
        }
        $required[] = $run;

        return $required;
    }

    /**
     * What every branch requires, by equal strings.
     *
     * @return list<string>
     */
    private function shared(AlternationHir $hir): array
    {
        $branches = $hir->branches;
        $shared = self::maximal($this->required(array_shift($branches)));
        foreach ($branches as $branch) {
            $shared = array_values(array_intersect($shared, self::maximal($this->required($branch))));
        }

        return $shared;
    }

    /**
     * @param list<int> $codePoints
     */
    private function text(array $codePoints): string
    {
        $text = '';
        foreach ($codePoints as $codePoint) {
            $text .= $this->unicode ? mb_chr($codePoint, 'UTF-8') : \chr($codePoint);
        }

        return $text;
    }

    /**
     * The non-empty strings, each once, without those another one holds.
     *
     * @param list<string> $strings
     *
     * @return list<string>
     */
    private static function maximal(array $strings): array
    {
        $strings = array_values(array_unique(array_filter($strings, static fn (string $string): bool => '' !== $string)));

        return array_values(array_filter($strings, static function (string $string) use ($strings): bool {
            foreach ($strings as $other) {
                if ($other !== $string && str_contains($other, $string)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
