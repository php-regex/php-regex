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

/**
 * Ordered registry of lint rules.
 *
 * The registration order is load-bearing: for each node type, rules run in
 * registry order, and finish() hooks run in registry order after traversal.
 * The default set reproduces the historical PatternLinter emission order.
 *
 * @internal
 */
final class LintRuleRegistry
{
    /**
     * @var list<LintRuleInterface>
     */
    private array $rules = [];

    public function __construct()
    {
        // Order must match historical PatternLinter emission order.
        $this->register(new RedundantCharClassRule());
        $this->register(new SuspiciousCharClassRangeRule());
        $this->register(new SuspiciousCharClassPipeRule());
        $this->register(new UselessCharClassRangeRule());
        $this->register(new DuplicateCharClassElementsRule());
        $this->register(new BackrefAsOctalInCharClassRule());
        $this->register(new LiteralMetacharInCharClassRule());
        $this->register(new EmptyAlternationRule());
        $this->register(new DuplicateDisjunctionRule());
        $this->register(new OverlappingAlternationRule());
        $this->register(new ImpossibleAnchorRule());
        $this->register(new QuantifierConcatenationRule());
        $this->register(new ZeroQuantifierRule());
        $this->register(new UselessQuantifierRule());
        $this->register(new NestedQuantifierRule());
        $this->register(new NestedDotStarRule());
        $this->register(new QuantifiedCapturingGroupRule());
        $this->register(new InlineFlagsRule());
        $this->register(new RedundantGroupRule());
        $this->register(new UndefinedBackrefRule());
        $this->register(new UselessBackrefRule());
        $this->register(new SuspiciousEscapeRule());
        $this->register(new BracedHexWithoutURule());
        $this->register(new UnicodePropertyWithoutURule());
        $this->register(new ShorthandWithoutURule());
        $this->register(new MultibyteInCharClassWithoutURule());
        $this->register(new QuantifiedMultibyteWithoutURule());
        $this->register(new QuantifiedAssertionRule());
        $this->register(new LazyEndRule());
        $this->register(new UselessIFlagRule());
        $this->register(new UselessSFlagRule());
        $this->register(new UselessMFlagRule());
        // Added after the historical set, so that its emission order stays
        // as it was.
        $this->register(new EmptyRepeatRule());
        $this->register(new AlternationPrecedenceRule());
        $this->register(new PossessiveImpossibleRule());
        $this->register(new ImpossibleLookaroundRule());
        $this->register(new EmptyGroupRule());
        $this->register(new SingleCharClassRule());
        $this->register(new MultipleSpacesRule());
        $this->register(new LazyToClassRule());
        $this->register(new UselessDFlagRule());
        $this->register(new UselessXFlagRule());
        $this->register(new UselessLazyRule());
        $this->register(new LookaroundEdgeQuantifierRule());
    }

    public function register(LintRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * @return list<LintRuleInterface>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
