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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\Token;

/**
 * What "(?C...)" holds, and where PCRE refuses one.
 *
 * A callout takes nothing, a number up to 255, or a string between one of
 * eight delimiters, a doubled closing delimiter standing for itself. PCRE
 * reads it from the text and stops at the first character it cannot take;
 * that place is the error offset, whether the callout stands alone or before
 * the assertion of a condition, so both readings ask here.
 *
 * @internal
 */
final class Callout
{
    /**
     * The delimiters PCRE2 takes around the string of a callout, each with
     * the one that closes it.
     */
    public const STRING_DELIMITERS = [
        '`' => '`', "'" => "'", '"' => '"', '^' => '^', '%' => '%', '#' => '#', '$' => '$', '{' => '}',
    ];

    /**
     * The characters around the argument: "(?C" and ")".
     */
    private const WRAPPER_LENGTH = 4;

    /**
     * The callout the lexer's token stands for, its value the argument as
     * written: "", "12", "{a}}b}".
     */
    public static function read(Token $token, string $pattern, PcreTarget $target): CalloutNode
    {
        $startPosition = $token->position;
        $value = $token->value;
        $endPosition = $startPosition + \strlen($token->value) + self::WRAPPER_LENGTH;

        if ('' === $value) {
            return new CalloutNode(null, false, $startPosition, $endPosition);
        }

        if (Ascii::isDigit($value)) {
            return new CalloutNode((int) $value, false, $startPosition, $endPosition);
        }

        $closing = self::STRING_DELIMITERS[$value[0]] ?? null;
        if (null === $closing) {
            // "(?Cab)": a callout takes a number or a delimited string;
            // "(?C1x)": the number is read, and ")" is due after it.
            $code = ErrorCode::CalloutInvalidDelimiter();
            if (1 === LibraryPcre::match('/^\d++/', $value, $number)) {
                $code = \strlen(ltrim($number[0], '0')) > 3 || (int) $number[0] > 255 ? ErrorCode::CalloutOutOfRange() : ErrorCode::CalloutUnclosed();
            }
            $position = self::fault($pattern, $startPosition, $target)[0] ?? $startPosition + 4;

            throw SyntaxErrorException::withContext(\sprintf('Invalid callout argument: %s at position %d: %s.', $value, $position, self::problem($code)), $code, $position, $pattern);
        }

        // A doubled closing delimiter stands for itself: "(?C{a}}b})".
        $quoted = preg_quote($closing, '/');
        if (1 !== LibraryPcre::match('/^.((?:[^'.$quoted.']|'.$quoted.$quoted.')*+)'.$quoted.'$/s', $value, $matches)) {
            // "(?C"a"b)": the string is closed, and ")" is due after it.
            $closed = 1 === LibraryPcre::match('/^.(?:[^'.$quoted.']|'.$quoted.$quoted.')*+'.$quoted.'/s', $value);
            $code = $closed ? ErrorCode::CalloutUnclosed() : ErrorCode::CalloutUnclosedString();
            $position = self::fault($pattern, $startPosition, $target)[0] ?? $startPosition;

            throw SyntaxErrorException::withContext(\sprintf('Invalid callout argument: %s at position %d: %s.', $value, $position, self::problem($code)), $code, $position, $pattern);
        }

        return new CalloutNode(str_replace($closing.$closing, $closing, $matches[1]), true, $startPosition, $endPosition);
    }

    /**
     * Where PCRE refuses a callout, and why, or null when it reads it: past
     * a digit that takes the number over 255, at a string delimiter never
     * closed, past a character that opens no string, where the ")" should
     * follow the argument, or at the end of a pattern that ends after "(?C".
     *
     * @param int $start the offset of the "(" of "(?C"
     *
     * @return array{0: int, 1: ErrorCode}|null
     */
    public static function fault(string $pattern, int $start, PcreTarget $target): ?array
    {
        $length = \strlen($pattern);
        $position = $start + 3;

        if ($position >= $length) {
            return [$length, ErrorCode::GroupUnclosed()];
        }

        if (Ascii::isDigit($pattern[$position])) {
            $number = 0;
            while ($position < $length && Ascii::isDigit($pattern[$position])) {
                $number = $number * 10 + (int) $pattern[$position++];
                if ($number > 255) {
                    return [$position, ErrorCode::CalloutOutOfRange()];
                }
            }
        } elseif (')' !== $pattern[$position]) {
            $closing = self::STRING_DELIMITERS[$pattern[$position]] ?? null;
            if (null === $closing) {
                return [$target->faultOffset($position + 1), ErrorCode::CalloutInvalidDelimiter()];
            }

            $opening = $position;
            while (true) {
                if (++$position >= $length) {
                    return [$opening, ErrorCode::CalloutUnclosedString()];
                }

                // A doubled closing delimiter stands for itself.
                if ($closing === $pattern[$position] && (++$position >= $length || $closing !== $pattern[$position])) {
                    break;
                }
            }
        }

        return ')' === ($pattern[$position] ?? '') ? null : [$position, ErrorCode::CalloutUnclosed()];
    }

    /**
     * What is wrong with a callout fault() refuses, in words.
     */
    public static function problem(ErrorCode $code): string
    {
        switch (true) {
            case $code === ErrorCode::CalloutOutOfRange():
                return 'the callout number is greater than 255';
            case $code === ErrorCode::CalloutInvalidDelimiter():
                return 'a callout takes a number, or a string opened by one of ` \' " ^ % # $ {';
            case $code === ErrorCode::CalloutUnclosedString():
                return 'the callout string is not closed by its delimiter';
            case $code === ErrorCode::CalloutUnclosed():
                return '")" is expected after the callout argument';
            default:
                return '")" is expected to close the callout';
        }
    }
}
