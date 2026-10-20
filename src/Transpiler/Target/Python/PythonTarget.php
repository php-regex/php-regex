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

namespace PHPRegex\Transpiler\Target\Python;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Transpiler\Target\TargetInterface;
use PHPRegex\Transpiler\TranspileContext;
use PHPRegex\Transpiler\TranspileException;

/**
 * Transpile target for Python 're' module.
 *
 * @internal
 */
final class PythonTarget implements TargetInterface
{
    /**
     * The re flags a pattern is given: ASCII, which PCRE's reading without
     * u matches, IGNORECASE and DOTALL. /m, /D and /x are applied while
     * printing, and /U by swapping greedy and lazy.
     */
    private const SUPPORTED_FLAGS = ['a', 'i', 's'];

    public function getName(): string
    {
        return 'python';
    }

    public function getAliases(): array
    {
        return ['py'];
    }

    public function getDefaultDelimiter(): string
    {
        return "'";
    }

    public function compile(RegexNode $ast, TranspileContext $context): string
    {
        $visitor = new PythonPrinter($context);

        return $ast->accept($visitor);
    }

    public function mapFlags(string $flags, TranspileContext $context): string
    {
        $normalized = '';
        $unsupported = [];

        foreach (Bytes::split($flags) as $flag) {
            $note = match ($flag) {
                'i', 's', 'u' => null,
                'm' => 'Applied /m: "^" and "$" are written for lines ended by "\n" alone, as PCRE reads them.',
                'D' => 'Applied /D: "$" is written for the end of the subject alone.',
                'x' => 'Applied /x (extended mode): whitespace and comments were removed during compilation.',
                'U' => 'Applied /U (ungreedy): greedy and lazy quantifiers were swapped.',
                'S' => 'Dropped /S: PHP has ignored it since 7.3, under PCRE2.',
                default => '',
            };

            if ('' === $note) {
                $unsupported[] = $flag;
            } elseif (null !== $note) {
                $context->addNote($note);
            } elseif ('u' !== $flag) {
                $normalized .= $flag;
            }
        }

        if ([] !== $unsupported) {
            throw new TranspileException('Unsupported PCRE flag(s) for Python: '.implode(', ', $unsupported).'.');
        }

        // Without u PCRE reads "\d", "\w", "\s", "\b" and case in ASCII;
        // Python reads them in Unicode but under re.ASCII.
        if (!str_contains($flags, 'u')) {
            $normalized .= 'a';
            $context->addNote('Added re.ASCII: without u, PCRE reads \d, \w, \s, \b and case in ASCII.');
        }

        return $this->normalizeFlagOrder($normalized);
    }

    public function formatLiteral(string $pattern, string $flags, TranspileContext $context): string
    {
        // Python has no regex literal, so the pattern is a string — and a
        // string carries no flags. They are spelled inline instead, which
        // Python only accepts at the very start of the pattern.
        return $this->quote(('' === $flags ? '' : '(?'.$flags.')').$pattern);
    }

    public function formatConstructor(string $pattern, string $flags, TranspileContext $context): string
    {
        // re.compile(r'pattern', flags)
        $flagConstants = [];
        if (str_contains($flags, 'a')) {
            $flagConstants[] = 're.ASCII';
        }
        if (str_contains($flags, 'i')) {
            $flagConstants[] = 're.IGNORECASE';
        }
        if (str_contains($flags, 's')) {
            $flagConstants[] = 're.DOTALL';
        }

        $flagsStr = [] === $flagConstants ? '0' : implode(' | ', $flagConstants);

        return 're.compile('.$this->quote($pattern).', '.$flagsStr.')';
    }

    /**
     * Spell a pattern as a Python string literal.
     *
     * A raw string is what a regex wants, since it leaves the backslashes
     * alone — but it cannot hold the quote that delimits it, and it cannot
     * end with a backslash. Backslash-escaping the quote inside a raw string
     * keeps the backslash in the pattern, so the quote character decides
     * instead, and a pattern that rules out both falls back to an ordinary
     * string with its backslashes doubled.
     */
    private function quote(string $pattern): string
    {
        $endsWithBackslash = 1 === LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*\\\\$/', $pattern);

        if (!$endsWithBackslash && !str_contains($pattern, "'")) {
            return "r'".$pattern."'";
        }

        if (!$endsWithBackslash && !str_contains($pattern, '"')) {
            return 'r"'.$pattern.'"';
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $pattern)."'";
    }

    private function normalizeFlagOrder(string $flags): string
    {
        $ordered = [];
        foreach (self::SUPPORTED_FLAGS as $flag) {
            if (str_contains($flags, $flag)) {
                $ordered[] = $flag;
            }
        }

        return implode('', $ordered);
    }
}
