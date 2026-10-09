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

use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Transpiler\Target\TargetInterface;
use PHPRegex\Transpiler\TranspileContext;
use PHPRegex\Transpiler\TranspileException;

/**
 * Transpile target for the HTML pattern attribute: the browser matches the
 * whole value against "^(?:" . pattern . ")$" under the v flag alone
 * (WHATWG HTML), where preg_match() searches. The value is padded with
 * "[\s\S]*" on each side the pattern does not anchor, and printed with the
 * class escapes the v flag requires. No flag can be passed: /i and "(?i)"
 * are spelled out, each letter written with every case PCRE takes for it.
 *
 * @internal
 */
final readonly class HtmlPatternTarget implements TargetInterface
{
    private const PADDING = '[\s\S]*';

    public function getName(): string
    {
        return 'html-pattern';
    }

    public function getAliases(): array
    {
        return ['html'];
    }

    public function getDefaultDelimiter(): string
    {
        return '';
    }

    public function compile(RegexNode $ast, TranspileContext $context): string
    {
        $body = $ast->accept(new JavaScriptPrinter($context, $context->options->allowLookbehind, $this->getDefaultDelimiter(), true));

        $items = $ast->pattern instanceof SequenceNode
            ? array_values(array_filter($ast->pattern->children, static fn (NodeInterface $child): bool => !$child instanceof CommentNode && !JavaScriptPrinter::isBareOptionSetting($child)))
            : [$ast->pattern];
        $first = $items[0] ?? null;
        $last = $items[\count($items) - 1] ?? null;
        $startAnchored = ($first instanceof AnchorNode && '^' === $first->value) || ($first instanceof AssertionNode && 'A' === $first->value);
        $endAnchored = ($last instanceof AnchorNode && '$' === $last->value) || ($last instanceof AssertionNode && \in_array($last->value, ['z', 'Z'], true));

        if ($startAnchored && $endAnchored) {
            return $body;
        }

        return ($startAnchored ? '' : self::PADDING).'(?:'.$body.')'.($endAnchored ? '' : self::PADDING);
    }

    public function mapFlags(string $flags, TranspileContext $context): string
    {
        // The v flag reads code points, as /u does; a value holds no line
        // break for /s, /m or /D to matter; /i is spelled out by the printer.
        $unsupported = array_diff(str_split($flags), ['', 'i', 'u', 'D', 's', 'm', 'x', 'S']);
        if (str_contains($flags, 'x')) {
            $context->addNote('Applied /x (extended mode): whitespace and comments were removed during compilation.');
        }

        if (str_contains($flags, 'S')) {
            $context->addNote('Dropped /S: PHP has ignored it since 7.3, under PCRE2.');
        }

        if (str_contains($flags, 's') || str_contains($flags, 'm')) {
            $context->addNote('A field value holds no line break: /s and /m change nothing there.');
        }

        if ([] !== $unsupported) {
            throw new TranspileException('The HTML pattern attribute takes no flags: /'.implode('', $unsupported).' cannot be carried.');
        }

        return 'v';
    }

    public function formatLiteral(string $pattern, string $flags, TranspileContext $context): string
    {
        return $pattern;
    }

    public function formatConstructor(string $pattern, string $flags, TranspileContext $context): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t", "\u{2028}", "\u{2029}"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t', '\\u2028', '\\u2029'],
            '^(?:'.$pattern.')$',
        );

        return 'new RegExp("'.$escaped.'", "v")';
    }
}
