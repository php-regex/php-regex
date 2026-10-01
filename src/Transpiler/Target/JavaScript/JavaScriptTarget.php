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

namespace PHPRegex\Transpiler\Target\JavaScript;

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Transpiler\Target\TargetInterface;
use PHPRegex\Transpiler\TranspileContext;
use PHPRegex\Transpiler\TranspileException;

/**
 * Transpile target for JavaScript RegExp.
 */
final readonly class JavaScriptTarget implements TargetInterface
{
    private const SUPPORTED_FLAGS = ['i', 'm', 's', 'u'];

    public function getName(): string
    {
        return 'javascript';
    }

    public function getAliases(): array
    {
        return ['js'];
    }

    public function getDefaultDelimiter(): string
    {
        return '/';
    }

    public function compile(RegexNode $ast, TranspileContext $context): string
    {
        $visitor = new JavaScriptPrinter(
            $context,
            $context->options->allowLookbehind,
            $this->getDefaultDelimiter(),
        );

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

            if ('x' === $flag) {
                $context->addNote('Applied /x (extended mode): whitespace and comments were removed during compilation.');

                continue;
            }

            $unsupported[] = $flag;
        }

        if ([] !== $unsupported) {
            throw new TranspileException('Unsupported PCRE flag(s) for JavaScript: '.implode(', ', $unsupported).'.');
        }

        foreach ($context->getRequiredFlags() as $flag => $reason) {
            if (!str_contains($normalized, $flag)) {
                $normalized .= $flag;
                $context->addWarning($reason);
            }
        }

        $normalized = implode('', array_unique(str_split($normalized)));

        return $this->normalizeFlagOrder($normalized);
    }

    public function formatLiteral(string $pattern, string $flags, TranspileContext $context): string
    {
        $delimiter = $this->getDefaultDelimiter();

        return $delimiter.$pattern.$delimiter.$flags;
    }

    public function formatConstructor(string $pattern, string $flags, TranspileContext $context): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t", "\u{2028}", "\u{2029}"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t', '\\u2028', '\\u2029'],
            $pattern,
        );

        return 'new RegExp("'.$escaped.'", "'.$flags.'")';
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
