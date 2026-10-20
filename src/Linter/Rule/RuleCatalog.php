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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Linter\LintService;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Text\NearMiss;

/**
 * What the library knows of each lint rule and of each analysis issue:
 * its identifier, what it reports, whether it runs when regex.json does not
 * mention it, and the section of the docs that explains it.
 *
 * The regex.json schema, the link every report gives (JSON, Checkstyle,
 * GitHub, the PHPStan tip, the editor's diagnostic) and the validation of a
 * rules map all read it, so a rule is described in one place.
 *
 * @internal
 */
final class RuleCatalog
{
    /**
     * A warning for a pattern whose complexity score reaches the threshold.
     */
    public const COMPLEXITY = 'regex.lint.complexity';

    /**
     * A later PHP of the project range reads the pattern another way.
     */
    public const MEANING_CHANGES = 'regex.lint.compat.meaningChanges';

    /**
     * A PHP file the lint could not read, whose patterns were not linted.
     */
    public const SOURCE_UNREADABLE = 'regex.lint.source.unreadable';

    /**
     * Every lint rule, in full: what it reports, whether it runs when
     * regex.json does not mention it, and its section of the docs.
     */
    private const LINT_RULES = [
        ShorthandWithoutURule::ID => ['Warn when \w, \d, \s are used without /u flag (ASCII-only matching).', false, 'https://php-regex.com/reference/rules/#unicode-shorthand-without-u'],
        UnicodePropertyWithoutURule::ID => ['Error when \p{...} Unicode properties are used without /u flag.', true, 'https://php-regex.com/reference/rules/#unicode-property-without-u'],
        BracedHexWithoutURule::ID => ['Error when \x{...} escapes with code points > 0xFF are used without /u flag.', true, 'https://php-regex.com/reference/rules/#braced-hex-escape-without-u'],
        MultibyteInCharClassWithoutURule::ID => ['Error when a multibyte character sits in a character class without /u flag (the class matches each byte).', true, 'https://php-regex.com/reference/rules/#multibyte-character-in-a-class'],
        QuantifiedMultibyteWithoutURule::ID => ['Error when a quantifier follows a multibyte character without /u flag (only its last byte repeats).', true, 'https://php-regex.com/reference/rules/#quantifier-after-a-multibyte-character'],
        UselessIFlagRule::ID => ['Warn when /i flag is used but pattern has no case-sensitive characters.', true, 'https://php-regex.com/reference/rules/#useless-flag-i-caseless'],
        UselessMFlagRule::ID => ['Warn when /m flag is used but pattern has no anchors.', true, 'https://php-regex.com/reference/rules/#useless-flag-m-multiline'],
        UselessSFlagRule::ID => ['Warn when /s flag is used but pattern has no unescaped dot outside a character class.', true, 'https://php-regex.com/reference/rules/#useless-flag-s-dotall'],
        UselessDFlagRule::ID => ['Warn when /D flag is used but no $ anchor is read without m.', true, 'https://php-regex.com/reference/rules/#useless-flag-d-dollar-end-only'],
        UselessXFlagRule::ID => ['Warn when /x flag is used but pattern has no whitespace and no # comment.', true, 'https://php-regex.com/reference/rules/#useless-flag-x-extended'],
        InlineFlagsRule::ID_REDUNDANT => ['Warn about redundant flag modifiers.', true, 'https://php-regex.com/reference/rules/#inline-flag-redundant'],
        InlineFlagsRule::ID_OVERRIDE => ['Warn when inline flags override outer flags.', true, 'https://php-regex.com/reference/rules/#inline-flag-override'],
        RedundantCharClassRule::ID => ['Warn about redundant character classes like [a].', true, 'https://php-regex.com/reference/rules/#redundant-character-class-elements'],
        SuspiciousCharClassRangeRule::ID => ['Warn about suspicious character ranges like [A-z].', true, 'https://php-regex.com/reference/rules/#suspicious-ascii-ranges'],
        SuspiciousCharClassPipeRule::ID => ['Warn about literal pipe in character class (likely meant alternation).', true, 'https://php-regex.com/reference/rules/#alternation-like-character-classes'],
        DuplicateCharClassElementsRule::ID => ['Warn about duplicate elements in character classes.', true, 'https://php-regex.com/reference/rules/#duplicate-character-class-elements'],
        BackrefAsOctalInCharClassRule::ID => ['Warn when \1-\9 in character class is octal, not a backreference.', true, 'https://php-regex.com/reference/rules/#backreference-written-inside-a-character-class'],
        LiteralMetacharInCharClassRule::ID => ['Warn about unescaped metacharacters in character classes.', true, 'https://php-regex.com/reference/rules/#literal-metacharacter-in-a-character-class'],
        EmptyAlternationRule::ID => ['Warn about empty alternatives in alternations.', true, 'https://php-regex.com/reference/rules/#empty-alternatives'],
        DuplicateDisjunctionRule::ID => ['Warn about duplicate branches in alternations.', true, 'https://php-regex.com/reference/rules/#duplicate-alternation-branches'],
        OverlappingAlternationRule::ID_OVERLAP => ['Warn about overlapping alternatives that may cause backtracking.', true, 'https://php-regex.com/reference/rules/#overlapping-alternation-branches'],
        OverlappingAlternationRule::ID_DOT_NEWLINE => ['Warn about (.|\n) anti-pattern for matching any character.', true, 'https://php-regex.com/reference/rules/#dot-or-newline-alternation'],
        UselessQuantifierRule::ID => ['Warn about useless quantifiers like {1}.', true, 'https://php-regex.com/reference/rules/#useless-quantifier'],
        ZeroQuantifierRule::ID => ['Warn about quantifiers that always match zero times.', true, 'https://php-regex.com/reference/rules/#zero-quantifier'],
        NestedQuantifierRule::ID => ['Warn about nested quantifiers that may cause catastrophic backtracking.', true, 'https://php-regex.com/reference/rules/#nested-quantifiers-redos-risk'],
        QuantifierConcatenationRule::ID => ['Warn about concatenated quantifiers that can be optimized.', true, 'https://php-regex.com/reference/rules/#optimal-quantifier-concatenation'],
        QuantifiedAssertionRule::ID => ['Warn about a quantifier on a lookaround, which lets the match skip it or changes nothing.', true, 'https://php-regex.com/reference/rules/#quantified-assertion'],
        LazyEndRule::ID => ['Warn about a lazy quantifier nothing follows, which always matches its minimum.', true, 'https://php-regex.com/reference/rules/#lazy-quantifier-at-the-end'],
        RedundantGroupRule::ID => ['Warn about redundant non-capturing groups.', true, 'https://php-regex.com/reference/rules/#redundant-non-capturing-group'],
        QuantifiedCapturingGroupRule::ID => ['Warn about quantified capturing groups where only last match is retained.', true, 'https://php-regex.com/reference/rules/#quantified-capturing-group'],
        ImpossibleAnchorRule::ID_START => ['Warn about impossible start anchor positions.', true, 'https://php-regex.com/reference/rules/#anchor-conflicts'],
        ImpossibleAnchorRule::ID_END => ['Warn about impossible end anchor positions.', true, 'https://php-regex.com/reference/rules/#anchor-conflicts'],
        UndefinedBackrefRule::ID => ['Warn about references to undefined groups.', true, 'https://php-regex.com/reference/rules/#undefined-backreference'],
        UselessBackrefRule::ID => ['Warn about useless backreferences.', true, 'https://php-regex.com/reference/rules/#useless-backreferences'],
        NestedDotStarRule::ID => ['Warn about nested dot-star patterns that cause severe backtracking.', true, 'https://php-regex.com/reference/rules/#dot-star-in-quantifier'],
        SuspiciousEscapeRule::ID => ['Warn about suspicious escape sequences.', true, 'https://php-regex.com/reference/rules/#suspicious-escapes'],
        UselessCharClassRangeRule::ID => ['Warn about useless character ranges.', true, 'https://php-regex.com/reference/rules/#useless-character-range'],
        OverlappingAlternationRule::ID_CHARSET => ['Warn about overlapping character sets in alternations.', true, 'https://php-regex.com/reference/rules/#overlapping-character-sets'],
        EmptyRepeatRule::ID => ['Warn about an unbounded quantifier on an item that can match the empty string.', true, 'https://php-regex.com/reference/rules/#repeat-that-can-match-empty'],
        AlternationPrecedenceRule::ID => ['Warn when an anchor on the first or last alternative leaves another alternative unanchored, as in ^a|b.', true, 'https://php-regex.com/reference/rules/#anchor-precedence-in-alternation'],
        PossessiveImpossibleRule::ID => ['Warn about a possessive repeat that takes every character the atom after it could read.', true, 'https://php-regex.com/reference/rules/#impossible-possessive-quantifier'],
        ImpossibleAnchorRule::ID_BOUNDARY => ['Warn about a word boundary its two neighbouring characters contradict.', true, 'https://php-regex.com/reference/rules/#anchor-conflicts'],
        ImpossibleLookaroundRule::ID => ['Warn about a lookahead the pattern after it contradicts.', true, 'https://php-regex.com/reference/rules/#impossible-lookaround'],
        AlwaysEmptyCaptureRule::ID => ['Warn about a capturing group that always captures an empty string.', true, 'https://php-regex.com/reference/rules/#capture-that-never-captures-text'],
        EmptyGroupRule::ID => ['Warn about an empty non-capturing or atomic group.', true, 'https://php-regex.com/reference/rules/#empty-group'],
        SingleCharClassRule::ID => ['Report a character class holding a single character (style).', false, 'https://php-regex.com/reference/rules/#single-character-class'],
        MultipleSpacesRule::ID => ['Report a run of literal spaces, clearer as a counted repeat (style).', false, 'https://php-regex.com/reference/rules/#multiple-spaces'],
        LazyToClassRule::ID => ['Report a lazy dot before a single closing character, faster as a negated class (perf).', false, 'https://php-regex.com/reference/rules/#lazy-quantifier-before-a-delimiter'],
        UselessLazyRule::ID => ['Report a lazy quantifier that matches what the greedy one matches (style).', false, 'https://php-regex.com/reference/rules/#useless-lazy-quantifier'],
        LookaroundEdgeQuantifierRule::ID => ['Report a repeat at the edge of a lookaround past its minimum, which is never checked (perf).', false, 'https://php-regex.com/reference/rules/#repeat-at-the-edge-of-a-lookaround'],
        DollarNewlineRule::ID => ['Report a final $ in a pattern anchored at its start, which also matches before a trailing newline (info).', false, 'https://php-regex.com/reference/rules/#dollar-before-a-trailing-newline'],
        InvisibleCharacterRule::ID => ['Warn about a control character, or a character that hides or moves text, written raw in a pattern.', true, 'https://php-regex.com/reference/rules/#invisible-character-written-raw'],
        RedundantLookaroundRule::ID => ['Report a lookaround at the edge of a lookaround read the same way, which can hold its body (style).', false, 'https://php-regex.com/reference/rules/#lookaround-inside-a-lookaround'],
    ];

    /**
     * The issues of an analysis the rules map turns off like a lint rule,
     * though no lint rule reports them, as LINT_RULES lists the rules.
     */
    private const ANALYSIS_RULES = [
        RedosVerdict::SEARCH_ISSUE_ID => ['Report the quadratic cost of an unanchored search whose every attempt is proven linear. It runs under the ReDoS check and is medium: a threshold of medium or low shows it.', true, 'https://php-regex.com/reference/rules/#quadratic-search'],
        self::MEANING_CHANGES => ['Warn when a later PHP of the project range parses the pattern into another meaning, as "a{,3}" (text before PCRE2 10.43, a quantifier from it).', true, 'https://php-regex.com/reference/rules/#meaning-that-changes-across-php-versions'],
    ];

    /**
     * The other issues the library reports under an identifier of its own,
     * which a check of their own turns on or off, by their section of the
     * docs.
     */
    private const OTHER_ISSUES = [
        RedosVerdict::ISSUE_ID => 'https://php-regex.com/reference/rules/#catastrophic-backtracking',
        self::COMPLEXITY => 'https://php-regex.com/reference/rules/#complexity-threshold',
        self::SOURCE_UNREADABLE => 'https://php-regex.com/reference/rules/#unreadable-source-file',
        LintService::ISSUE_ID_UNMATCHED_IGNORE => 'https://php-regex.com/guides/cli/#markers-that-silence-nothing',
        LintService::ISSUE_ID_TARGET_INVALID => 'https://php-regex.com/guides/cli/#code-that-runs-on-some-php-versions-only',
        LintService::ISSUE_ID_TARGET_EMPTY => 'https://php-regex.com/guides/cli/#code-that-runs-on-some-php-versions-only',
    ];

    private function __construct() {}

    /**
     * The lint rules, by their short identifier, as regex.json names them.
     *
     * @return list<string>
     */
    public static function lintRuleIds(): array
    {
        return array_map(RuleId::short(...), array_keys(self::LINT_RULES));
    }

    /**
     * What a rules map may name, by short identifier: the lint rules, then
     * the analysis issues it turns off the same way.
     *
     * @return list<string>
     */
    public static function configurableIds(): array
    {
        return array_map(RuleId::short(...), array_keys([...self::LINT_RULES, ...self::ANALYSIS_RULES]));
    }

    /**
     * The lint rules that run only when a rules map turns them on, by
     * short identifier.
     *
     * @return list<string>
     */
    public static function rulesOffByDefault(): array
    {
        $off = [];
        foreach (self::LINT_RULES as $id => [, $default]) {
            if (!$default) {
                $off[] = RuleId::short($id);
            }
        }

        return $off;
    }

    /**
     * Whether a rules map may name the identifier, short or in full: a lint
     * rule, or an analysis issue it turns off the same way.
     */
    public static function isConfigurable(string $id): bool
    {
        return null !== self::entry($id);
    }

    /**
     * What the rule or the analysis issue reports, short or in full; null
     * for an identifier a rules map may not name.
     */
    public static function description(string $id): ?string
    {
        return self::entry($id)[0] ?? null;
    }

    /**
     * The identifier a rules map may name that the one given most likely
     * means, spelled as it was, short or in full; null when none is near.
     */
    public static function suggestion(string $id): ?string
    {
        $closest = NearMiss::closest(RuleId::short($id), self::configurableIds());
        if (null === $closest) {
            return null;
        }

        return str_starts_with($id, RuleId::PREFIX) ? RuleId::full($closest) : $closest;
    }

    /**
     * Whether a rules map, as regex.json's checks.lint.rules writes it,
     * turns a check on: false under either spelling of its identifier turns
     * it off, true under one turns it on, else it runs as it does by
     * default. An identifier the catalogue does not know runs.
     *
     * @param array<string, bool> $rules
     */
    public static function enables(array $rules, string $id): bool
    {
        $settings = array_intersect_key($rules, [RuleId::short($id) => true, RuleId::full($id) => true]);
        if ([] !== $settings) {
            return !\in_array(false, $settings, true);
        }

        return self::entry($id)[1] ?? true;
    }

    /**
     * The section of the docs that explains an issue, by the identifier the
     * issue carries; null for one the catalogue does not hold, the error
     * code of an invalid pattern included.
     */
    public static function docsUrl(string $issueId): ?string
    {
        return self::docsUrls()[$issueId] ?? null;
    }

    /**
     * The section of the docs of every issue the catalogue holds, by full
     * identifier.
     *
     * @return array<string, string>
     */
    public static function docsUrls(): array
    {
        $urls = [];
        foreach ([...self::LINT_RULES, ...self::ANALYSIS_RULES] as $id => [, , $url]) {
            $urls[$id] = $url;
        }

        return $urls + self::OTHER_ISSUES;
    }

    /**
     * The entry of a lint rule or of an analysis issue a rules map may
     * name, short or in full.
     *
     * @return array{string, bool, string}|null
     */
    private static function entry(string $id): ?array
    {
        $full = RuleId::full($id);

        return self::LINT_RULES[$full] ?? self::ANALYSIS_RULES[$full] ?? null;
    }
}
