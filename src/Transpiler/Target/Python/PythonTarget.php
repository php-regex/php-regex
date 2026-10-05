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

use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Transpiler\Target\TargetInterface;
use PHPRegex\Transpiler\TranspileContext;
use PHPRegex\Transpiler\TranspileException;

/**
 * Transpile target for Python 're' module.
 *
 * @internal
 */
final readonly class PythonTarget implements TargetInterface
{
    // Python re flags: ASCII (a), IGNORECASE (i), LOCALE (L), MULTILINE (m), DOTALL (s), VERBOSE (x)
    // We map PCRE flags to these.
    private const SUPPORTED_FLAGS = ['i', 'm', 's', 'x'];

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

        foreach (str_split($flags) as $flag) {
            if (\in_array($flag, self::SUPPORTED_FLAGS, true)) {
                $normalized .= $flag;

                continue;
            }

            if ('u' === $flag) {
                // Python 3 implies unicode by default for str regexes.
                // We don't need to add a flag, but we should probably note it.
                continue;
            }

            if ('U' === $flag) {
                // PCRE inverts every quantifier with /U; Python only marks
                // them one by one, so there is nothing to map it to.
                $context->addWarning('Dropped /U (ungreedy) flag; Python requires per-quantifier ungreedy (?).');

                continue;
            }

            $unsupported[] = $flag;
        }

        if ([] !== $unsupported) {
            throw new TranspileException('Unsupported PCRE flag(s) for Python: '.implode(', ', $unsupported).'.');
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
        if (str_contains($flags, 'i')) {
            $flagConstants[] = 're.IGNORECASE';
        }
        if (str_contains($flags, 'm')) {
            $flagConstants[] = 're.MULTILINE';
        }
        if (str_contains($flags, 's')) {
            $flagConstants[] = 're.DOTALL';
        }
        if (str_contains($flags, 'x')) {
            $flagConstants[] = 're.VERBOSE';
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
