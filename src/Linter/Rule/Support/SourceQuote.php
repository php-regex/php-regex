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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Printer\PatternPrinter;

/**
 * A piece of the pattern as it is written, kept short enough to read in a
 * message: the reader tells two findings of one rule apart by it.
 *
 * @internal
 */
final class SourceQuote
{
    private const COLUMNS = 30;

    private function __construct() {}

    /**
     * The node as the pattern writes it, on one line and thirty columns at
     * most. The piece of the body is spelled in the pattern's mode, a
     * hidden character "\x{HEX}" under u and each byte past ASCII "\xHH"
     * otherwise; the printed form when the body does not hold the node.
     * The layout x skips and the comments are left out, as they would fill
     * the columns before the first atom of a node laid out over lines.
     */
    public static function of(NodeInterface $node, LintContext $context): string
    {
        $text = self::withoutLayout($node, $context->pattern->source);
        if ('' === $text) {
            $text = $node->accept(PatternPrinter::asWritten());
        }

        $shown = DisplayEscaper::escapeFragment($text, $context->pattern->unicodeMode);
        if (mb_strlen($shown, 'UTF-8') <= self::COLUMNS) {
            return $shown;
        }

        // Cut as the reader sees it, on thirty columns at most, and never
        // inside an escape: "\x{202E}", "\xE9" and "\d" stay whole.
        $kept = '';
        LibraryPcre::matchAll('/\\\\x\{[0-9A-Fa-f]+\}|\\\\x[0-9A-Fa-f]{2}|\\\\.|./su', $shown, $tokens);
        foreach ($tokens[0] ?? [] as $token) {
            if (mb_strlen($kept.$token, 'UTF-8') > self::COLUMNS) {
                break;
            }

            $kept .= $token;
        }

        return $kept.'…';
    }

    /**
     * The piece of the body a node covers, without the layout x skips and
     * without the comments, "(?#...)" and the "# ..." x reads up to the
     * line end. A leaf and a class are taken as written, an escaped space
     * and a space in a class included. Between its children a node holds
     * its own syntax, "(?:", ")", "|" or a quantifier, which no whitespace
     * belongs to, and the whitespace x skipped: that whitespace goes. The
     * parser places every child inside its parent, in order, the body of a
     * verb included.
     */
    private static function withoutLayout(NodeInterface $node, string $source): string
    {
        if ($node instanceof CommentNode) {
            return '';
        }

        $start = $node->getStartPosition();
        $end = $node->getEndPosition();
        $written = substr($source, $start, $end - $start);
        $children = $node instanceof CharClassNode ? [] : $node->getChildren();
        if ([] === $children) {
            return $written;
        }

        $text = '';
        $at = $start;
        foreach ($children as $child) {
            $text .= self::withoutWhitespace(substr($source, $at, $child->getStartPosition() - $at)).self::withoutLayout($child, $source);
            $at = $child->getEndPosition();
        }

        return $text.self::withoutWhitespace(substr($source, $at, $end - $at));
    }

    private static function withoutWhitespace(string $syntax): string
    {
        return str_replace([' ', "\t", "\n", "\r", "\f", "\v"], '', $syntax);
    }
}
