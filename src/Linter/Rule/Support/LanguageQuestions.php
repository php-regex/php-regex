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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\RegexParser;

/**
 * The questions lint rules put to the automata, about patterns built from
 * the text of the one being linted: the same delimiter, start options and
 * flags, the inline flags in force spelled out. The work is capped below
 * the solver's own defaults, so that a lint run stays quick; past the cap,
 * or outside the regular subset, there is no answer and the rule stays
 * silent. A rule passes the DFA cache it keeps for its own questions: a
 * pattern repeats its atoms, and nothing is kept past the rule.
 *
 * @internal
 */
final class LanguageQuestions
{
    /**
     * The inline flags whose state is spelled out: i, m and s change what
     * matches, x how the text reads.
     */
    private const INLINE_FLAGS = 'imsx';

    /**
     * Caseless matching restricted to one script, spelled out besides the
     * others in a pattern that uses it.
     */
    private const CASELESS_RESTRICT = 'r';

    /**
     * The parser the solver reads the questions with.
     */
    private static ?RegexParser $parser = null;

    private function __construct() {}

    /**
     * The options every question runs under: a few hundred states at most.
     */
    public static function options(): SolverOptions
    {
        return new SolverOptions(maxNfaStates: 200, maxDfaStates: 200, maxTransitionsProcessed: 20_000);
    }

    /**
     * The inline flags the questions about the linted pattern spell out:
     * i, m, s and x, and r in a pattern that uses it anywhere (a parser for
     * a PCRE2 older than 10.43 refuses the letter). Null when the pattern
     * sets an option the flags in force do not follow, whose questions
     * would then be asked under the wrong one: the ASCII options ("(?a)",
     * "(?aD)", ...), kept to none of the flags, and "(?xx)", kept as "x".
     *
     * @param NodeInterface $node the node the rule checks, the root when no
     *                            parent holds it
     */
    public static function spelledFlags(LintContext $context, NodeInterface $node): ?string
    {
        $restricted = str_contains($context->pattern->flags, self::CASELESS_RESTRICT);
        foreach (self::optionSettings($context->parents()[0] ?? $node) as $setting) {
            $set = explode('-', $setting, 2)[0];
            if (str_contains($setting, 'a') || str_contains($set, 'xx')) {
                return null;
            }

            $restricted = $restricted || str_contains($setting, self::CASELESS_RESTRICT);
        }

        return self::INLINE_FLAGS.($restricted ? self::CASELESS_RESTRICT : '');
    }

    /**
     * A pattern made of the body, read under the flags given, with the
     * linted pattern's delimiter, start options and modifiers; $spelled
     * comes from spelledFlags(). The group spells "r" out wherever the
     * modifier would set it, so the modifier, which the automata do not
     * read, is left off.
     */
    public static function pattern(LintContext $context, string $body, string $flags, string $spelled): string
    {
        $pattern = $context->pattern;

        return $pattern->delimiter
            .StartOptions::of($pattern->source)
            .self::scoped($body, $flags, $spelled)
            .PatternParser::closingDelimiter($pattern->delimiter)
            .str_replace(self::CASELESS_RESTRICT, '', $pattern->flags);
    }

    /**
     * The body in a group that sets every inline flag of $spelled the way
     * $flags has it, whatever the flags around the group: "(?i-msx:...)".
     */
    public static function scoped(string $body, string $flags, string $spelled): string
    {
        $on = '';
        $off = '';
        foreach (str_split($spelled) as $flag) {
            if (str_contains($flags, $flag)) {
                $on .= $flag;
            } else {
                $off .= $flag;
            }
        }

        return '(?'.$on.('' === $off ? '' : '-'.$off).':'.$body.')';
    }

    /**
     * Whether the node is an atom that reads exactly one character, whose
     * set the automata can then be asked about: a literal of one character
     * (one byte without UTF mode), an escape, a class, a dot, a property or
     * a type such as "\d" ("\R" and "\X" read more than one).
     */
    public static function isOneCharacter(NodeInterface $node, bool $unicode): bool
    {
        if ($node instanceof LiteralNode) {
            return 1 === ($unicode ? mb_strlen($node->value, 'UTF-8') : \strlen($node->value));
        }

        if ($node instanceof CharTypeNode) {
            return !\in_array($node->value, ['R', 'X', 'C'], true);
        }

        return $node instanceof CharLiteralNode
            || $node instanceof CharClassNode
            || $node instanceof DotNode
            || $node instanceof UnicodePropNode;
    }

    /**
     * The node as the linted pattern writes it.
     */
    public static function text(NodeInterface $node, LintContext $context): string
    {
        $start = $node->getStartPosition();
        $length = $node->getEndPosition() - $start;

        return $start >= 0 && $length > 0 ? substr($context->pattern->source, $start, $length) : '';
    }

    /**
     * Whether every string the left pattern matches whole, the right one
     * matches whole; null when the automata cannot say.
     */
    public static function isSubset(string $left, string $right, ?DfaCacheInterface $dfas = null): ?bool
    {
        return self::ask(static fn (LanguageSolver $solver): bool => $solver->subsetOf($left, $right, self::options())->isSubset, $dfas);
    }

    /**
     * Whether no string matches both patterns whole; null when the
     * automata cannot say.
     */
    public static function areDisjoint(string $left, string $right, ?DfaCacheInterface $dfas = null): ?bool
    {
        return self::ask(static fn (LanguageSolver $solver): bool => $solver->intersection($left, $right, self::options())->isEmpty, $dfas);
    }

    /**
     * Whether the pattern matches no string whole; null when the automata
     * cannot say.
     */
    public static function matchesNothing(string $pattern, ?DfaCacheInterface $dfas = null): ?bool
    {
        // The search for a common string walks the alphabet's ranges, where
        // the language's own emptiness check reads every code point.
        return self::ask(static fn (LanguageSolver $solver): bool => $solver->intersection($pattern, $pattern, self::options())->isEmpty, $dfas);
    }

    /**
     * Whether preg_match() writes the same $matches for both patterns on
     * every subject; null when the automata cannot say.
     */
    public static function matchTheSame(string $left, string $right, ?DfaCacheInterface $dfas = null): ?bool
    {
        return self::ask(static fn (LanguageSolver $solver): bool => $solver->matchEquivalent($left, $right, self::options())->isEquivalent, $dfas);
    }

    /**
     * Whether the linted pattern and the same pattern with the bytes from
     * $start to $end replaced write the same $matches wherever the search
     * starts. matchTheSame() reads a search from offset 0, where a start
     * anchor holds; preg_match_all() goes on, and an offset starts, where
     * none does, so a pattern holding one is asked again with every start
     * anchor made to fail ("[^\s\S]" reads nothing). Null when the automata
     * cannot say.
     */
    public static function matchTheSameFromAnyOffset(LintContext $context, int $start, int $end, string $replacement): ?bool
    {
        $pattern = $context->pattern;
        $wrap = static fn (string $body): string => $pattern->delimiter.$body.PatternParser::closingDelimiter($pattern->delimiter).$pattern->flags;
        $edit = [$start, $end, $replacement];

        $same = self::matchTheSame($wrap($pattern->source), $wrap(self::spliced($pattern->source, [$edit])));
        $root = $context->parents()[0] ?? null;
        if (true !== $same || null === $root || !NodePredicates::holdsAStartAnchor($root)) {
            return $same;
        }

        $anchors = [];
        foreach (self::startAnchors($root) as $anchor) {
            $anchors[] = [$anchor->getStartPosition(), $anchor->getEndPosition(), '[^\s\S]'];
        }
        $outside = array_values(array_filter($anchors, static fn (array $anchor): bool => $anchor[1] <= $start || $anchor[0] >= $end));

        return self::matchTheSame($wrap(self::spliced($pattern->source, $anchors)), $wrap(self::spliced($pattern->source, [...$outside, $edit])));
    }

    /**
     * @return list<NodeInterface>
     */
    private static function startAnchors(NodeInterface $node): array
    {
        $found = NodePredicates::isStartAnchorNode($node) ? [$node] : [];
        foreach ($node->getChildren() as $child) {
            array_push($found, ...self::startAnchors($child));
        }

        return $found;
    }

    /**
     * The source with each [start, end, replacement] applied; the ranges do
     * not overlap.
     *
     * @param list<array{int, int, string}> $edits
     */
    private static function spliced(string $source, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $replacement]) {
            $source = substr($source, 0, $start).$replacement.substr($source, $end);
        }

        return $source;
    }

    /**
     * The option settings of the inline flag groups under the node, as
     * written: "i-s", "^x", "aD".
     *
     * @return iterable<string>
     */
    private static function optionSettings(NodeInterface $node): iterable
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && null !== $node->flags) {
            yield $node->flags;
        }

        foreach ($node->getChildren() as $child) {
            yield from self::optionSettings($child);
        }
    }

    /**
     * @param \Closure(LanguageSolver): bool $question
     */
    private static function ask(\Closure $question, ?DfaCacheInterface $dfas): ?bool
    {
        try {
            return $question(new LanguageSolver(self::$parser ??= RegexParser::create(), $dfas));
        } catch (RegexException) {
            // Outside the regular subset, past the cap, or a pattern the
            // parser refuses: no answer.
            return null;
        }
    }
}
