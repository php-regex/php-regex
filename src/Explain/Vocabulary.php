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

namespace PHPRegex\Explain;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Text\Ascii;

/**
 * The words the text and the HTML explanations describe a construct with,
 * so both say the same.
 *
 * @internal
 */
final class Vocabulary
{
    public const CHAR_TYPES = [
        'd' => 'A digit: [0-9]',
        'D' => 'A non-digit: [^0-9]',
        'h' => 'A horizontal whitespace character: [ \\t\\xA0\\u1680\\u180e\\u2000-\\u200a\\u202f\\u205f\\u3000]',
        'H' => 'A non-horizontal whitespace character: [^\\h]',
        's' => 'A whitespace character: [ \\t\\n\\x0B\\f\\r]',
        'S' => 'A non-whitespace character: [^\\s]',
        'v' => 'A vertical whitespace character: [\\n\\x0B\\f\\r\\x85\\u2028\\u2029]',
        'V' => 'A non-vertical whitespace character: [^\\v]',
        'w' => 'A word character: [a-zA-Z_0-9]',
        'W' => 'A non-word character: [^\\w]',
        'R' => 'Any Unicode linebreak sequence (\\u000D\\u000A|[\\u000A\\u000B\\u000C\\u000D\\u0085\\u2028\\u2029])',
    ];

    public const ANCHORS = [
        '^' => 'the beginning of a line',
        '$' => 'the end of a line',
    ];

    public const ASSERTIONS = [
        'A' => 'the beginning of the input',
        'z' => 'the end of the input',
        'Z' => 'the end of the input but for the final terminator, if any',
        'G' => 'the end of the previous match',
        'b' => 'a word boundary',
        'B' => 'a non-word boundary',
    ];

    public const UNICODE_PROPERTIES = [
        'lower' => 'A lower-case alphabetic character: [a-z]',
        'upper' => 'An upper-case alphabetic character: [A-Z]',
        'ascii' => 'All ASCII: [\\x00-\\x7F]',
        'alpha' => 'An alphabetic character: [\\p{Lower}\\p{Upper}]',
        'digit' => 'A decimal digit: [0-9]',
        'alnum' => 'An alphanumeric character: [\\p{Alpha}\\p{Digit}]',
        'punct' => 'Punctuation: One of !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~',
        'graph' => 'A visible character: [\\p{Alnum}\\p{Punct}]',
        'print' => 'A printable character: [\\p{Graph}\\x20]',
        'blank' => 'A space or a tab: [ \\t]',
        'cntrl' => 'A control character: [\\x00-\\x1F\\x7F]',
        'xdigit' => 'A hexadecimal digit: [0-9a-fA-F]',
        'space' => 'A whitespace character: [ \\t\\n\\x0B\\f\\r]',
        'javalowercase' => 'Equivalent to java.lang.Character.isLowerCase()',
        'javauppercase' => 'Equivalent to java.lang.Character.isUpperCase()',
        'javawhitespace' => 'Equivalent to java.lang.Character.isWhitespace()',
        'javamirrored' => 'Equivalent to java.lang.Character.isMirrored()',
        'islatin' => 'A Latin script character (script)',
        'ingreek' => 'A character in the Greek block (block)',
        'lu' => 'An uppercase letter (category)',
        'isalphabetic' => 'An alphabetic character (binary property)',
        'sc' => 'A currency symbol',
    ];

    /**
     * How many times a quantifier repeats, and how greedily.
     */
    public static function quantifier(string $q, QuantifierType $type): string
    {
        $bounds = QuantifierBounds::parse($q);
        $desc = match (true) {
            '*' === $q => 'zero or more times',
            '+' === $q => 'one or more times',
            '?' === $q => 'once or not at all',
            null === $bounds => 'with quantifier '.$q, // Fallback
            $bounds->min === $bounds->max => \sprintf('exactly %d times', $bounds->min),
            null === $bounds->max => \sprintf('at least %d times', $bounds->min),
            default => \sprintf('at least %d but not more than %d times', $bounds->min, $bounds->max),
        };

        $desc .= match ($type) {
            QuantifierType::Lazy => ' (as few as possible)',
            QuantifierType::Possessive => ' (and do not backtrack)',
            default => '',
        };

        return $desc;
    }

    /**
     * A literal, quoted, a blank or a control character named.
     */
    public static function literal(string $value): string
    {
        return match ($value) {
            '' => "'' (empty string)",
            ' ' => "' ' (space)",
            "\t" => "'\\t' (tab)",
            "\n" => "'\\n' (newline)",
            "\r" => "'\\r' (carriage return)",
            "\f" => "'\\f' (form feed)",
            "\x07" => "'\\a' (bell)",
            "\x1B" => "'\\e' (escape)",
            default => self::charLiteral($value),
        };
    }

    /**
     * What a Unicode property describes, negated or not; null for a
     * property the table does not hold.
     *
     * @param string $key the property's name, lower case
     */
    public static function unicodeProperty(string $key, bool $negated): ?string
    {
        if (!isset(self::UNICODE_PROPERTIES[$key])) {
            return null;
        }

        if (!$negated) {
            return self::UNICODE_PROPERTIES[$key];
        }

        if ('ingreek' === $key) {
            return 'Any character except one in the Greek block (block)';
        }

        return 'Any character except '.Ascii::lowerFirst(self::UNICODE_PROPERTIES[$key]);
    }

    /**
     * The hexadecimal value of a character code, upper case.
     */
    public static function unicodeHexValue(CharLiteralNode $node): string
    {
        $rep = $node->originalRepresentation;
        if (LibraryPcre::match('/^\\\\x([0-9a-fA-F]{1,2})$/', $rep, $matches)) {
            return Ascii::upper($matches[1]);
        }

        if (LibraryPcre::match('/^\\\\u([0-9a-fA-F]{4})$/', $rep, $matches)) {
            return Ascii::upper($matches[1]);
        }

        if (LibraryPcre::match('/^\\\\[xu]\\{([0-9a-fA-F]+)\\}$/', $rep, $matches)) {
            return Ascii::upper($matches[1]);
        }

        if (1 === \strlen($rep)) {
            return Ascii::upper(str_pad(dechex(\ord($rep)), 2, '0', \STR_PAD_LEFT));
        }

        return Ascii::upper($rep);
    }

    /**
     * The octal value of a \o{...} or \ddd code, with a leading 0.
     */
    public static function octalValue(CharLiteralNode $node): string
    {
        $rep = $node->originalRepresentation;
        if (LibraryPcre::match('/^\\\\o\\{([0-7]+)\\}$/', $rep, $matches)) {
            return '0'.$matches[1];
        }

        return self::legacyOctalValue($rep);
    }

    /**
     * The octal value of a \ddd code, with a leading 0.
     */
    public static function legacyOctalValue(string $value): string
    {
        $raw = str_starts_with($value, '\\') ? substr($value, 1) : $value;

        return str_starts_with($raw, '0') ? $raw : '0'.$raw;
    }

    private static function charLiteral(string $value): string
    {
        // The first byte, as ord() read it before PHP 8.5 deprecated
        // passing it anything but one byte.
        $ord = '' === $value ? 0 : \ord($value[0]);

        // Handle control characters and extended ASCII as hex codes
        if ($ord < 32 || 127 === $ord || $ord >= 128) {
            return "'\\x".Ascii::upper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT))."'";
        }

        // Printable characters
        return "'".$value."'";
    }
}
