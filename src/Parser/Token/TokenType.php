<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Token;

enum TokenType: string
{
    /**
     * A single literal character (e.g., "a", "1").
     */
    case T_LITERAL = 'literal';

    /**
     * A special character class type (e.g., \d, \s, \w).
     */
    case T_CHAR_TYPE = 'char_type';

    /**
     * A group opening parenthesis "(".
     */
    case T_GROUP_OPEN = 'group_open';

    /**
     * A group closing parenthesis ")".
     */
    case T_GROUP_CLOSE = 'group_close';

    /**
     * A special group opening sequence (e.g., "(?").
     */
    case T_GROUP_MODIFIER_OPEN = 'group_modifier_open';

    /**
     * A character class opening bracket "[".
     */
    case T_CHAR_CLASS_OPEN = 'char_class_open';

    /**
     * A character class closing bracket "]".
     */
    case T_CHAR_CLASS_CLOSE = 'char_class_close';

    /**
     * A quantifier (e.g., "*", "+", "?", "{n,m}", "*?", "++", "{n,m}+").
     */
    case T_QUANTIFIER = 'quantifier';

    /**
     * The alternation pipe "|".
     */
    case T_ALTERNATION = 'alternation';

    /**
     * The wildcard dot ".".
     */
    case T_DOT = 'dot';

    /**
     * An anchor (e.g., "^", "$").
     */
    case T_ANCHOR = 'anchor';

    /**
     * The end-of-file marker.
     */
    case T_EOF = 'eof';

    /**
     * A range operator "-" inside a character class.
     */
    case T_RANGE = 'range';

    /**
     * A negation operator "^" at the start of a character class.
     */
    case T_NEGATION = 'negation';

    /**
     * A backreference (e.g., "\1", "\k<name>").
     */
    case T_BACKREF = 'backref';

    /**
     * A Unicode escape (e.g., "\xHH", "\u{HHHH}").
     */
    case T_UNICODE = 'unicode';

    /**
     * A POSIX class inside a character class (e.g., "[:alpha:]").
     */
    case T_POSIX_CLASS = 'posix_class';

    /**
     * An assertion (e.g., \b, \B, \A, \z, \Z, \G).
     */
    case T_ASSERTION = 'assertion';

    /**
     * A Unicode property (e.g., \p{L}, \P{^L}).
     */
    case T_UNICODE_PROP = 'unicode_prop';

    /**
     * An octal escape (e.g., \o{777}).
     */
    case T_OCTAL = 'octal';

    /**
     * A legacy octal escape (e.g., \012).
     */
    case T_OCTAL_LEGACY = 'octal_legacy';

    /**
     * A comment opening in group (?#).
     */
    case T_COMMENT_OPEN = 'comment_open';

    /**
     * A PCRE verb (e.g., "(*FAIL)", "(*COMMIT)").
     */
    case T_PCRE_VERB = 'pcre_verb';

    /**
     * A \g reference (e.g., "\g{1}", "\g<name>", "\g-1").
     */
    case T_G_REFERENCE = 'g_reference';

    /**
     * The \K "keep" assertion.
     */
    case T_KEEP = 'keep';

    /**
     * A literal generated from an escaped sequence (e.g., "\*").
     */
    case T_LITERAL_ESCAPED = 'literal_escaped';

    /**
     * The \Q sequence start.
     */
    case T_QUOTE_MODE_START = 'quote_mode_start';

    /**
     * The \E sequence end.
     */
    case T_QUOTE_MODE_END = 'quote_mode_end';

    /**
     * A callout (e.g., "(?C1)", "(?C"arg")").
     */
    case T_CALLOUT = 'callout';

    /**
     * A named Unicode character (e.g., \N{name}).
     */
    case T_UNICODE_NAMED = 'unicode_named';

    /**
     * Control character escape (e.g., \cM).
     */
    case T_CONTROL_CHAR = 'control_char';

    /**
     * A Perl extended class "(?[...])" as a whole, PCRE2 10.45: its value is
     * the text from "(?[" to its "])", which the parser reads.
     */
    case T_EXTENDED_CLASS = 'extended_class';
}
