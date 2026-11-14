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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\VersionCondition;

/**
 * The checks of a conditional: how many branches it holds, two at most,
 * or one for a (DEFINE), and the version a "(?(VERSION>=10.4)" condition
 * compares with.
 *
 * PCRE counts the branches once it has resolved the references, after
 * errors written further on. Such an error goes to the validator, which
 * knows how far the walk has gone, and decides when it is raised. Where
 * PCRE reports it, an offset that moved between releases, is worked out
 * here.
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class ConditionalChecks
{
    /**
     */
    private PcreTarget $target;
    /**
     * @var \Closure(SemanticErrorException): void
     *
     */
    private \Closure $branchCount;
    /**
     */
    private ?string $pattern = null;
    /**
     */
    private ?string $source = null;
    /**
     */
    private int $offset = 0;
    /**
     */
    private bool $utf = false;

    /**
     * @param PcreTarget                             $target      the PHP and PCRE2 judged
     * @param \Closure(SemanticErrorException): void $branchCount told of a conditional
     *                                                            of too many branches,
     *                                                            which PCRE refuses once
     *                                                            the references are resolved
     * @param string|null                            $pattern     the whole pattern, which
     *                                                            the errors quote
     * @param string|null                            $source      the text the positions of
     *                                                            the conditionals point
     *                                                            into; null when nothing
     *                                                            says what was written
     * @param int                                    $offset      where that text starts in
     *                                                            the pattern
     * @param bool                                   $utf         whether PCRE reads the
     *                                                            pattern as UTF-8
     */
    public function __construct(PcreTarget $target, \Closure $branchCount, ?string $pattern = null, ?string $source = null, int $offset = 0, bool $utf = false)
    {
        $this->target = $target;
        $this->branchCount = $branchCount;
        $this->pattern = $pattern;
        $this->source = $source;
        $this->offset = $offset;
        $this->utf = $utf;
    }

    /**
     * The same checks on another text of the pattern, which starts at
     * $offset in it.
     */
    public function reading(?string $source, int $offset): self
    {
        return new self($this->target, $this->branchCount, $this->pattern, $source, $offset, $this->utf);
    }

    /**
     * The parser keeps every branch of a conditional after the first in
     * "no"; PCRE takes two at most.
     */
    public function branches(ConditionalNode $node): void
    {
        if ($node->no instanceof AlternationNode) {
            $this->tooManyBranches(
                'A conditional group holds more than two branches.',
                $this->branchCountErrorOffset($node),
                ErrorCode::ConditionalTooManyBranches(),
                'Group the extra branches: (?(1)a|(?:b|c)).',
            );
        }
    }

    /**
     * A (DEFINE) group holds one branch, reported at the "DEFINE" word,
     * past "(?(".
     */
    public function defineBranches(DefineNode $node): void
    {
        if ($node->content instanceof AlternationNode) {
            $this->tooManyBranches(
                'A (DEFINE) group holds more than one branch.',
                $node->startPosition + 3,
                ErrorCode::DefineTooManyBranches(),
                'Group the branches: (?(DEFINE)(?:a|b)).',
            );
        }
    }

    /**
     * PCRE compares the version two ways and no more, with a major number
     * and at most one ".minor", each up to 1000.
     *
     * The parser reads the others so that a pattern using one still produces
     * a tree to look at; saying they will not compile is the job of these
     * checks.
     */
    public function versionCondition(VersionConditionNode $node): void
    {
        $versionAt = null === $this->source ? false : strpos($this->source, 'VERSION', $node->startPosition);
        $pcreOffset = false === $versionAt ? null : VersionCondition::errorOffsetFor((string) $this->source, $versionAt, $this->target, $this->utf);

        if (!\in_array($node->operator, ['=', '>='], true)) {
            $this->raise(
                \sprintf('Version condition "%s" is not supported: PCRE compares with "=" or ">=".', $node->operator),
                $pcreOffset ?? $node->startPosition,
                ErrorCode::ConditionalVersionOperator(),
            );
        }

        // PCRE reads a major number and at most one ".minor".
        if (1 === LibraryPcre::match('/^\d++(?:\.\d++)?$/', $node->version)) {
            // "(?(VERSION=10 )": before PCRE2 10.47, what follows the major
            // where the ")" belongs leaves the condition open.
            if (null !== $pcreOffset && false !== $versionAt && !$this->target->supports(PcreFeature::VersionConditionLeftOpenIsVersionError())
                && VersionCondition::isMajorLeftOpenAt((string) $this->source, $versionAt, $pcreOffset)) {
                $this->raise(
                    \sprintf('Missing ")" to close the condition at position %d.', $pcreOffset),
                    $pcreOffset,
                    ErrorCode::ConditionalUnclosed(),
                );
            }

            if (null !== $pcreOffset) {
                $this->raise(
                    \sprintf('Invalid version "%s" in a version condition: the number is too big.', $node->version),
                    $pcreOffset,
                    ErrorCode::ConditionalVersionSyntax(),
                    'PCRE takes a major and a minor of at most 1000, and before PCRE2 10.47 a minor of two digits.',
                );
            }

            return;
        }

        // The pattern matches every string, the empty prefix included: the
        // '' branch is unreachable and only there for the type.
        $valid = 1 === LibraryPcre::match('/^\d*+(?:\.\d*+)?/', $node->version, $matches) ? $matches[0] : '';
        $afterNumber = '' !== $valid && 0 !== substr_compare($valid, '.', -strlen('.'));
        $versionStart = null === $this->source
            ? $node->startPosition
            : (int) strpos($this->source, $node->version, $node->startPosition);

        $this->raise(
            \sprintf('Invalid version "%s" in a version condition: PCRE takes a major number and an optional ".minor".', $node->version),
            // From PCRE2 10.47, PCRE steps past a character it reads where
            // ")" belongs; it stops on one it reads where a digit belongs.
            // Before, it stops on a third digit of a minor.
            $pcreOffset ?? ($afterNumber ? $this->target->faultOffset($versionStart + \strlen($valid) + 1) : $versionStart + \strlen($valid)),
            ErrorCode::ConditionalVersionSyntax(),
        );
    }

    /**
     * Where PCRE reports a conditional that holds more than two branches:
     * where the group opens, one character on for each character of a group
     * number past its first, as in "(?(+1)"; before PCRE2 10.47, on the name
     * the condition tests.
     */
    private function branchCountErrorOffset(ConditionalNode $node): int
    {
        $condition = $node->condition;
        if ($condition instanceof BackrefNode && 1 === LibraryPcre::match('/^[+-]?\d++$/', $condition->ref)) {
            return $node->startPosition + \strlen($condition->ref) - 1;
        }

        if ($this->target->supports(PcreFeature::BranchCountErrorOffTheConditionName())
            || !($condition instanceof BackrefNode || $condition instanceof SubroutineNode)) {
            return $node->startPosition;
        }

        switch (true) {
            case true === ($condition instanceof SubroutineNode && 0 === strncmp($condition->reference, 'R&', strlen('R&'))):
                return $condition->startPosition + 2;
            case true === \in_array($this->source[$condition->startPosition] ?? '', ['<', "'"], true):
                return $condition->startPosition + 1;
            default:
                return $condition->startPosition + 0;
        }
    }

    /**
     * A conditional with more than two branches, or a (DEFINE) with more
     * than one: PCRE counts them once it has resolved the references, which
     * the validator knows.
     */
    private function tooManyBranches(string $message, int $position, ErrorCode $code, string $hint): void
    {
        ($this->branchCount)(new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, null, null, $hint));
    }

    /**
     * @return never
     */
    private function raise(string $message, int $position, ErrorCode $code, ?string $hint = null)
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, null, null, $hint);
    }
}
