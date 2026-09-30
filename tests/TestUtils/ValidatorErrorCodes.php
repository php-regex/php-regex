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

namespace RegexParser\Tests\TestUtils;

/**
 * The error codes the AST validator emitted before the error-code enum
 * existed. Their string values are public contract: callers match on them,
 * so each one keeps its exact value as an enum case.
 */
final class ValidatorErrorCodes
{
    public const VALUES = [
        'regex.assertion.invalid',
        'regex.backref.invalid_syntax',
        'regex.backref.missing_group',
        'regex.backref.missing_named_group',
        'regex.backref.relative',
        'regex.backref.zero',
        'regex.callout.out_of_range',
        'regex.charclass.invalid_escape',
        'regex.condition.version_operator',
        'regex.condition.version_syntax',
        'regex.conditional.invalid',
        'regex.conditional.too_many_branches',
        'regex.control_char.invalid',
        'regex.define.too_many_branches',
        'regex.escape.digits_missing',
        'regex.escape.single_byte_in_utf',
        'regex.escape.unrecognized',
        'regex.escape.unsupported',
        'regex.group.nested_too_deep',
        'regex.keep.in_lookaround',
        'regex.lookbehind.too_complex',
        'regex.lookbehind.too_long',
        'regex.lookbehind.unbounded',
        'regex.lookbehind.variable_length_not_supported',
        'regex.octal.invalid_digit',
        'regex.octal.missing_brace',
        'regex.octal.out_of_range',
        'regex.pattern.too_large',
        'regex.posix.collating_element',
        'regex.posix.invalid',
        'regex.posix.outside_class',
        'regex.quantifier.invalid_range',
        'regex.quantifier.too_big',
        'regex.range.invalid_bounds',
        'regex.range.invalid_end',
        'regex.range.invalid_start',
        'regex.range.reversed',
        'regex.subroutine.missing_group',
        'regex.subroutine.missing_named_group',
        'regex.subroutine.recursion',
        'regex.subroutine.relative_missing',
        'regex.subroutine.relative_zero',
        'regex.unicode_named.requires_utf',
        'regex.unicode.invalid_digit',
        'regex.unicode.out_of_range',
        'regex.unicode.property_invalid',
        'regex.unicode.property_malformed',
        'regex.unicode.surrogate',
        'regex.verb.conflicting_casings',
        'regex.verb.invalid',
        'regex.verb.limit_too_large',
        'regex.verb.mark_name_missing',
        'regex.verb.misplaced',
        'regex.verb.name_too_long',
        'regex.verb.turkish_casing_without_utf',
    ];

    /**
     * The codes other subsystems emitted before the enum: runtime
     * compilation, the complexity budget, transpilation and sample
     * generation.
     */
    public const OTHER_VALUES = [
        'regex.pcre.runtime',
        'regex.complexity',
        'regex.transpile.unsupported',
        'regex.generate.no_match',
    ];

    /**
     * The catch-all codes every syntax error used to share, and the default
     * of a semantic error nobody emitted: none of them survives.
     */
    public const GENERIC_VALUES = [
        'parser.error',
        'lexer.error',
        'regex.semantic',
    ];
}
