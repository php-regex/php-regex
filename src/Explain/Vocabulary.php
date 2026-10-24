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

use PHPRegex\Parser\BsrConvention;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\NewlineConvention;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

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
        'w' => 'A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C',
        'W' => 'A non-word character: [^\\w]',
        'R' => 'Any Unicode linebreak sequence (\\u000D\\u000A|[\\u000A\\u000B\\u000C\\u000D\\u0085\\u2028\\u2029])',
        'X' => 'An extended grapheme cluster: a character with the marks that combine with it, or a \\r\\n pair',
        'C' => 'One code unit: a single byte, a newline included',
    ];

    /**
     * The properties described by name, keyed by the name as PCRE2 matches
     * it from 10.40: lower case, without spaces, hyphens or underscores.
     * Only names some PCRE2 release takes: Java's, the "Is" and "In"
     * prefixes and the POSIX class names are refused by every one.
     */
    public const UNICODE_PROPERTIES = [
        'lower' => 'A lowercase character (the Unicode Lowercase property)',
        'lowercase' => 'A lowercase character (the Unicode Lowercase property)',
        'upper' => 'An uppercase character (the Unicode Uppercase property)',
        'uppercase' => 'An uppercase character (the Unicode Uppercase property)',
        'alpha' => 'An alphabetic character (the Unicode Alphabetic property)',
        'alphabetic' => 'An alphabetic character (the Unicode Alphabetic property)',
        'ascii' => 'An ASCII character: [\\x00-\\x7F]',
        'space' => 'A whitespace character (the Unicode White_Space property)',
        'whitespace' => 'A whitespace character (the Unicode White_Space property)',
        'wspace' => 'A whitespace character (the Unicode White_Space property)',
        'lu' => 'An uppercase letter (category)',
        'sc' => 'A currency symbol (category)',
    ];

    /**
     * What "\d", "\s" and "\w" and their negations match under Unicode
     * properties, as PCRE2 reads them under UCP.
     */
    public const UNICODE_CHAR_TYPES = [
        'd' => 'A decimal digit: any Unicode decimal digit (\\p{Nd})',
        'D' => 'A non-digit: any character but a Unicode decimal digit (\\P{Nd})',
        's' => 'A whitespace character: a Unicode separator (\\p{Z}), or a horizontal or vertical whitespace character (\\h, \\v)',
        'S' => 'A non-whitespace character: [^\\s]',
        'w' => 'A word character: a Unicode letter (\\p{L}) or number (\\p{N}), an underscore, and from PCRE2 10.43 a non-spacing mark (\\p{Mn}) or connector punctuation (\\p{Pc})',
        'W' => 'A non-word character: [^\\w]',
    ];

    /**
     * The types that read one byte without UTF, where they match no
     * character past 0xFF.
     */
    private const BYTE_CHAR_TYPES = [
        'h' => 'A horizontal whitespace character: [ \\t\\xA0]',
        'v' => 'A vertical whitespace character: [\\n\\x0B\\f\\r\\x85]',
        'R' => 'Any linebreak sequence (\\r\\n|[\\n\\x0B\\f\\r\\x85])',
    ];

    /**
     * What a character type matches, by the settings in force: "\d", "\s"
     * and "\w" by the Unicode tables under Unicode properties, by ASCII
     * otherwise, "\h", "\v" and "\R" past 0xFF under UTF only, "\R" by what
     * a linebreak is, "\N" by what a newline is; null for a type the table
     * does not hold.
     */
    public static function charType(string $type, MatchSettings $settings): ?string
    {
        if (isset(self::UNICODE_CHAR_TYPES[$type]) && $settings->unicodeType($type)) {
            return self::UNICODE_CHAR_TYPES[$type];
        }

        if ('N' === $type) {
            return \sprintf('Any character but %s, whatever s says', self::newline($settings->newline));
        }

        if ('R' === $type && BsrConvention::AnyCrLf === $settings->bsr) {
            return 'A CR, LF or CRLF linebreak sequence, as (*BSR_ANYCRLF) sets: (\\r\\n|[\\n\\r])';
        }

        if (!$settings->utf && isset(self::BYTE_CHAR_TYPES[$type])) {
            return self::BYTE_CHAR_TYPES[$type];
        }

        return self::CHAR_TYPES[$type] ?? null;
    }

    /**
     * The test a condition makes, when it is a group reference or a
     * recursion check: whether the group has matched, whether a recursion
     * is in progress. Null for a lookaround or a version, explained as
     * themselves.
     */
    public static function condition(NodeInterface $condition): ?string
    {
        if ($condition instanceof BackrefNode) {
            return self::group($condition->ref).' has matched';
        }

        if (!$condition instanceof SubroutineNode) {
            return null;
        }

        $group = ltrim(substr($condition->reference, 1), '&');

        return '' === $group
            ? 'a recursion is in progress, into the whole pattern or any group'
            : \sprintf('the most recent recursion is into the capturing group "%s"', $group);
    }

    /**
     * What a backreference matches, "\1", "\g{-1}" or "\k<name>": what its
     * group matched.
     */
    public static function backreference(string $reference): string
    {
        return 'whatever '.self::group(LibraryPcre::replace('/^\\\\[gk]?|[{}<>\']/', '', $reference) ?? $reference).' matched';
    }

    /**
     * What a dot matches: a newline too under s.
     */
    public static function dot(MatchSettings $settings): string
    {
        return $settings->dotAll ? 'any character, a newline included' : 'any character but '.self::newline($settings->newline);
    }

    /**
     * Where "^" or "$" matches, by the flags in force: with m at the start
     * or end of any line, without it at the start of the subject and at its
     * end or, unless D, before a newline that ends it.
     */
    public static function anchor(string $anchor, MatchSettings $settings): string
    {
        $newline = self::newline($settings->newline);

        return match (true) {
            '^' === $anchor && $settings->multiline => \sprintf('the start of a line: the start of the subject, or just after %s that does not end it', $newline),
            '^' === $anchor => 'the start of the subject',
            '$' !== $anchor => $anchor,
            $settings->multiline => \sprintf('the end of a line: just before %s, or at the end of the subject', $newline),
            $settings->dollarEndOnly => 'the end of the subject',
            default => \sprintf('the end of the subject, or just before %s that ends it', $newline),
        };
    }

    /**
     * Where an assertion escape matches, "\A", "\z", "\Z", "\G", "\b" or
     * "\B": none of them reads m or D, "\Z" reads the newline convention.
     */
    public static function assertion(string $assertion, MatchSettings $settings): string
    {
        return match ($assertion) {
            'A' => 'the start of the subject',
            'z' => 'the end of the subject',
            'Z' => \sprintf('the end of the subject, or just before %s that ends it', self::newline($settings->newline)),
            'G' => 'where the match attempt starts: the offset the search starts at, or the end of the previous match',
            'b' => 'a word boundary',
            'B' => 'a non-word boundary',
            default => '\\'.$assertion,
        };
    }

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
     *
     * @param bool $utf whether the pattern is read as UTF: a character
     *                  above ASCII is then one character, else its bytes
     */
    public static function literal(string $value, bool $utf = false): string
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
            default => self::charLiteral($value, $utf),
        };
    }

    /**
     * What a Unicode property describes, negated or not; null for a
     * property the table does not hold.
     *
     * @param string $key the property's name, lower case; spaces, hyphens
     *                    and underscores are passed over, as PCRE2 does
     */
    public static function unicodeProperty(string $key, bool $negated): ?string
    {
        $description = self::UNICODE_PROPERTIES[str_replace([' ', '-', '_'], '', $key)] ?? null;
        if (null === $description || !$negated) {
            return $description;
        }

        return 'Any character except '.Ascii::lowerFirst($description);
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

    /**
     * The group a reference names, by number or name, or counted back or
     * ahead from where it stands.
     */
    private static function group(string $reference): string
    {
        return match ($reference[0] ?? '') {
            '-' => \sprintf('the capturing group %s back from here', substr($reference, 1)),
            '+' => \sprintf('the capturing group %s ahead of here', substr($reference, 1)),
            default => \sprintf('the capturing group "%s"', $reference),
        };
    }

    /**
     * What a newline is under a newline convention.
     */
    private static function newline(NewlineConvention $convention): string
    {
        return 'a newline ('.match ($convention) {
            NewlineConvention::Cr => '\\r',
            NewlineConvention::Lf => '\\n',
            NewlineConvention::CrLf => '\\r\\n',
            NewlineConvention::AnyCrLf => '\\r, \\n or \\r\\n',
            NewlineConvention::Any => 'any Unicode line break',
            NewlineConvention::Nul => 'the NUL character',
        }.')';
    }

    /**
     * The characters of a literal, quoted: under UTF a character above
     * ASCII as itself, or as its code point "\x{HEX}" when it prints as
     * nothing; otherwise, as the engine reads the text a byte at a time,
     * each byte above ASCII and each control byte as "\xHH".
     */
    private static function charLiteral(string $value, bool $utf): string
    {
        $characters = $utf && mb_check_encoding($value, 'UTF-8') ? mb_str_split($value, 1, 'UTF-8') : Bytes::split($value);
        $shown = '';
        foreach ($characters as $character) {
            $ord = \ord($character[0]);
            $shown .= match (true) {
                \strlen($character) > 1 => DisplayEscaper::escapeFragment($character, true),
                $ord < 32 || $ord >= 127 => '\\x'.Ascii::upper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT)),
                default => $character,
            };
        }

        return "'".$shown."'";
    }
}
