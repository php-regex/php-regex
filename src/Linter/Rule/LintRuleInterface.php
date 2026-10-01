<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule;

use PhpRegex\Parser\Node\NodeInterface;

/**
 * A single lint rule (or a small cluster of rules whose diagnostics interleave).
 *
 * Rules are dispatched by the PatternLinter traversal engine: check() is
 * called when the traversal enters a node whose class is listed in
 * getNodeTypes(), and finish() is called once after the traversal completes.
 * Rules return their issues; enablement filtering and ordering are handled by
 * the engine.
 */
interface LintRuleInterface
{
    /**
     * Fully-qualified rule IDs this rule can emit, e.g. 'regex.lint.quantifier.zero'.
     *
     * @return non-empty-list<string>
     */
    public function getRuleIds(): array;

    /**
     * Node classes this rule wants to inspect on node-enter.
     *
     * @return non-empty-list<class-string<NodeInterface>>
     */
    public function getNodeTypes(): array;

    /**
     * Reset per-pattern state. Called once per lint run before traversal.
     */
    public function begin(LintContext $context): void;

    /**
     * Inspect a node the rule subscribed to.
     *
     * @return list<RuleViolation>
     */
    public function check(NodeInterface $node, LintContext $context): array;

    /**
     * Emit issues that depend on state aggregated across the whole traversal.
     *
     * @return list<RuleViolation>
     */
    public function finish(LintContext $context): array;
}
