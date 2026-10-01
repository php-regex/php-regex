<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule\Support;

use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;

/**
 * Code-point parsing and hint-formatting helpers shared by lint rules.
 *
 * @internal
 */
final class CodePoints
{
    private function __construct() {}

    public static function fromNode(NodeInterface $node, bool $unicodeMode): ?int
    {
        if ($node instanceof LiteralNode) {
            return self::fromLiteral($node->value, $unicodeMode);
        }

        if ($node instanceof CharLiteralNode) {
            return $node->codePoint >= 0 ? $node->codePoint : null;
        }

        return null;
    }

    public static function fromLiteral(string $value, bool $unicodeMode): ?int
    {
        if ('' === $value) {
            return null;
        }

        if ($unicodeMode) {
            $chars = preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY);
            if (false === $chars || 1 !== \count($chars)) {
                return null;
            }

            $codePoint = mb_ord($chars[0], 'UTF-8');

            return false === $codePoint ? null : $codePoint;
        }

        if (1 !== \strlen($value)) {
            return null;
        }

        return \ord($value[0]);
    }

    public static function parseUnicodeEscape(string $escape): ?int
    {
        if (preg_match('/^\\\\x([0-9a-fA-F]{2})$/', $escape, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (preg_match('/^\\\\u([0-9a-fA-F]{4})$/', $escape, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (preg_match('/^\\\\[xu]\\{([0-9a-fA-F]++)\\}$/', $escape, $matches)) {
            return (int) hexdec($matches[1]);
        }

        return null;
    }

    public static function isBracedUnicodeEscape(string $escape): bool
    {
        return preg_match('/^\\\\[xu]\\{/', $escape) > 0;
    }

    public static function isAsciiLetter(int $ord): bool
    {
        return ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);
    }

    public static function formatCharLiteralForHint(string $value): string
    {
        $ord = \ord($value);

        if ($ord < 32 || 127 === $ord || $ord >= 128) {
            return "'\\x".strtoupper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT))."'";
        }

        if ("'" === $value) {
            return "'\\''";
        }

        if ('\\' === $value) {
            return "'\\\\'";
        }

        return "'".$value."'";
    }

    public static function formatCodePointForHint(int $codePoint): string
    {
        if ($codePoint >= 0 && $codePoint <= 0x7F) {
            return self::formatCharLiteralForHint(\chr($codePoint));
        }

        return "'\\x{".strtoupper(dechex($codePoint))."}'";
    }
}
