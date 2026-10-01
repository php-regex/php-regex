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

enum TokenType: string
{
    /**
     * A single literal character (e.g., "a", "1").
     */
    case Literal = 'literal';

    /**
     * A special character class type (e.g., \d, \s, \w).
     */
    case CharType = 'char_type';

    /**
     * A group opening parenthesis "(".
     */
    case GroupOpen = 'group_open';

    /**
     * A group closing parenthesis ")".
     */
    case GroupClose = 'group_close';

    /**
     * A special group opening sequence (e.g., "(?").
     */
    case GroupModifierOpen = 'group_modifier_open';

    /**
     * A character class opening bracket "[".
     */
    case CharClassOpen = 'char_class_open';

    /**
     * A character class closing bracket "]".
     */
    case CharClassClose = 'char_class_close';

    /**
     * A quantifier (e.g., "*", "+", "?", "{n,m}", "*?", "++", "{n,m}+").
     */
    case Quantifier = 'quantifier';

    /**
     * The alternation pipe "|".
     */
    case Alternation = 'alternation';

    /**
     * The wildcard dot ".".
     */
    case Dot = 'dot';

    /**
     * An anchor (e.g., "^", "$").
     */
    case Anchor = 'anchor';

    /**
     * The end-of-file marker.
     */
    case Eof = 'eof';

    /**
     * A range operator "-" inside a character class.
     */
    case Range = 'range';

    /**
     * A negation operator "^" at the start of a character class.
     */
    case Negation = 'negation';

    /**
     * A backreference (e.g., "\1", "\k<name>").
     */
    case Backref = 'backref';

    /**
     * A Unicode escape (e.g., "\xHH", "\u{HHHH}").
     */
    case Unicode = 'unicode';

    /**
     * A POSIX class inside a character class (e.g., "[:alpha:]").
     */
    case PosixClass = 'posix_class';

    /**
     * An assertion (e.g., \b, \B, \A, \z, \Z, \G).
     */
    case Assertion = 'assertion';

    /**
     * A Unicode property (e.g., \p{L}, \P{^L}).
     */
    case UnicodeProp = 'unicode_prop';

    /**
     * An octal escape (e.g., \o{777}).
     */
    case Octal = 'octal';

    /**
     * A legacy octal escape (e.g., \012).
     */
    case OctalLegacy = 'octal_legacy';

    /**
     * A comment opening in group (?#).
     */
    case CommentOpen = 'comment_open';

    /**
     * A PCRE verb (e.g., "(*FAIL)", "(*COMMIT)").
     */
    case PcreVerb = 'pcre_verb';

    /**
     * A \g reference (e.g., "\g{1}", "\g<name>", "\g-1").
     */
    case GReference = 'g_reference';

    /**
     * The \K "keep" assertion.
     */
    case Keep = 'keep';

    /**
     * A literal generated from an escaped sequence (e.g., "\*").
     */
    case LiteralEscaped = 'literal_escaped';

    /**
     * The \Q sequence start.
     */
    case QuoteModeStart = 'quote_mode_start';

    /**
     * The \E sequence end.
     */
    case QuoteModeEnd = 'quote_mode_end';

    /**
     * A callout (e.g., "(?C1)", "(?C"arg")").
     */
    case Callout = 'callout';

    /**
     * A named Unicode character (e.g., \N{name}).
     */
    case UnicodeNamed = 'unicode_named';

    /**
     * Control character escape (e.g., \cM).
     */
    case ControlChar = 'control_char';

    /**
     * A Perl extended class "(?[...])" as a whole, PCRE2 10.45: its value is
     * the text from "(?[" to its "])", which the parser reads.
     */
    case ExtendedClass = 'extended_class';
}
