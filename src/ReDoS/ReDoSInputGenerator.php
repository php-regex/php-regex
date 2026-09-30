<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\ReDoS;

use RegexParser\Analysis\CharSet;
use RegexParser\Analysis\CharSetAnalyzer;
use RegexParser\Node\NodeInterface;

/**
 * Generates a heuristic input string to demonstrate potential backtracking.
 */
final class ReDoSInputGenerator
{
    public function generate(NodeInterface $node, string $flags = '', ?ReDoSSeverity $severity = null): string
    {
        $analyzer = new CharSetAnalyzer($flags);
        $set = $analyzer->firstChars($node);
        $repeat = $this->repeatForSeverity($severity);

        $baseChar = $this->pickPrintable($set) ?? 'a';
        $suffixChar = $this->pickPrintable($set->complement()) ?? '!';

        return str_repeat($baseChar, $repeat).$suffixChar;
    }

    private function repeatForSeverity(?ReDoSSeverity $severity): int
    {
        return match ($severity) {
            ReDoSSeverity::CRITICAL => 50,
            ReDoSSeverity::HIGH => 40,
            ReDoSSeverity::MEDIUM => 30,
            ReDoSSeverity::LOW => 20,
            ReDoSSeverity::SAFE => 10,
            default => 25,
        };
    }

    private function pickPrintable(CharSet $set): ?string
    {
        $char = $set->sampleChar();
        if (null === $char) {
            return null;
        }

        $code = \ord($char);
        if ($code < 32 || $code > 126) {
            return null;
        }

        return $char;
    }
}
