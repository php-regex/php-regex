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

namespace PHPRegex\Parser\Token;

class TokenType
{
    public const Literal = 'literal';
    public const CharType = 'char_type';
    public const GroupOpen = 'group_open';
    public const GroupClose = 'group_close';
    public const GroupModifierOpen = 'group_modifier_open';
    public const CharClassOpen = 'char_class_open';
    public const CharClassClose = 'char_class_close';
    public const Quantifier = 'quantifier';
    public const Alternation = 'alternation';
    public const Dot = 'dot';
    public const Anchor = 'anchor';
    public const Eof = 'eof';
    public const Range = 'range';
    public const Negation = 'negation';
    public const Backref = 'backref';
    public const Unicode = 'unicode';
    public const PosixClass = 'posix_class';
    public const Assertion = 'assertion';
    public const UnicodeProp = 'unicode_prop';
    public const Octal = 'octal';
    public const OctalLegacy = 'octal_legacy';
    public const CommentOpen = 'comment_open';
    public const PcreVerb = 'pcre_verb';
    public const GReference = 'g_reference';
    public const Keep = 'keep';
    public const LiteralEscaped = 'literal_escaped';
    public const QuoteModeStart = 'quote_mode_start';
    public const QuoteModeEnd = 'quote_mode_end';
    public const Callout = 'callout';
    public const UnicodeNamed = 'unicode_named';
    public const ControlChar = 'control_char';
    public const ExtendedClass = 'extended_class';

    public static function from($value)
    {
        if (!\in_array($value, self::cases(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown value "%s" for %s.', $value, self::class));
        }

        return $value;
    }

    public static function tryFrom($value)
    {
        return \in_array($value, self::cases(), true) ? $value : null;
    }

    public static function cases()
    {
        return \array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
