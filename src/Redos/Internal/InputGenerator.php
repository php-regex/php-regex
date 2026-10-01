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

namespace PhpRegex\Redos\Internal;

use PhpRegex\Parser\Analysis\ByteCharSet;
use PhpRegex\Parser\Analysis\CharSetAnalyzer;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Redos\RedosSeverity;

/**
 * Generates a heuristic input string to demonstrate potential backtracking.
 */
final class InputGenerator
{
    public function generate(NodeInterface $node, string $flags = '', ?RedosSeverity $severity = null): string
    {
        $analyzer = new CharSetAnalyzer($flags);
        $set = $analyzer->firstChars($node);
        $repeat = $this->repeatForSeverity($severity);

        $baseChar = $this->pickPrintable($set) ?? 'a';
        $suffixChar = $this->pickPrintable($set->complement()) ?? '!';

        return str_repeat($baseChar, $repeat).$suffixChar;
    }

    private function repeatForSeverity(?RedosSeverity $severity): int
    {
        return match ($severity) {
            RedosSeverity::CRITICAL => 50,
            RedosSeverity::HIGH => 40,
            RedosSeverity::MEDIUM => 30,
            RedosSeverity::LOW => 20,
            RedosSeverity::SAFE => 10,
            default => 25,
        };
    }

    private function pickPrintable(ByteCharSet $set): ?string
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
