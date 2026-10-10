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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\Rule\LintRuleInterface;
use PHPRegex\Parser\Node\NodeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint rule contract is one template wide: a rule subscribes to node
 * classes (getNodeTypes()) and receives exactly those nodes in check() —
 * today both ends say NodeInterface, so a rule subscribing to BackrefNode
 * still reads its $node as any node and instanceof-narrows by hand. With
 * `@template TNode of NodeInterface` binding the list to the parameter
 * (`@return non-empty-list<class-string<TNode>>`, `@param TNode $node`), a
 * rule's `@extends` tag — AbstractLintRule bound to BackrefNode — makes
 * check() receive BackrefNode: the instanceof branches become dispatch
 * logic the analyser checks, and a rule subscribing to a class its check()
 * never reads becomes a static error instead of dead code.
 */
final class LintRuleTemplateTest extends TestCase
{
    #[Test]
    public function test_rule_interface_binds_its_node_types_through_a_template(): void
    {
        $docComment = (string) (new \ReflectionClass(LintRuleInterface::class))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@template\s+TNode\s+of\s+([\w\\\\]+)/', $docComment, $template),
            'LintRuleInterface must declare @template TNode of NodeInterface: getNodeTypes() and check() are the'
            .' two ends of one contract — the classes subscribed to are the class the checker receives — and only'
            .' a template makes the binding visible to rules and their readers.',
        );

        $this->assertSame(
            NodeInterface::class,
            self::resolveName($template[1]),
            'The template is bounded by NodeInterface: getNodeTypes() may list any node class, nothing else.',
        );
    }

    #[Test]
    public function test_get_node_types_returns_the_subscribed_class_strings(): void
    {
        $docComment = (string) (new \ReflectionMethod(LintRuleInterface::class, 'getNodeTypes'))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@return\s+non-empty-list<(.+)>/', $docComment, $return),
            'getNodeTypes() must stay non-empty: the dispatch engine registers a rule through the classes it asks'
            .' for, so an empty list is a rule that never runs.',
        );

        $this->assertSame(
            'class-string<TNode>',
            $return[1],
            sprintf(
                'getNodeTypes() must return class-string<TNode>, not %s: the list binds the rule\'s template, and'
                .' through it check() receives the very classes named here.',
                $return[1],
            ),
        );
    }

    #[Test]
    public function test_check_receives_the_subscribed_node(): void
    {
        $docComment = (string) (new \ReflectionMethod(LintRuleInterface::class, 'check'))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@param\s+(\S+)\s+\$node\b/', $docComment, $param),
            'check() must document its $node as TNode: the parameter is the template\'s other end — what the rule'
            .' subscribed to is what it inspects.',
        );

        $this->assertSame(
            'TNode',
            $param[1],
            sprintf('The @param for $node in check() must name the template TNode, not %s.', $param[1]),
        );
    }

    /**
     * A short name resolves through the interface's own imports.
     */
    private static function resolveName(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $source = (string) file_get_contents((string) (new \ReflectionClass(LintRuleInterface::class))->getFileName());
        if (preg_match_all('/^use\s+([\w\\\\]+);/m', $source, $statements, \PREG_SET_ORDER)) {
            foreach ($statements as $statement) {
                $short = substr($statement[1], (int) strrpos($statement[1], '\\') + 1);
                if ($short === $name) {
                    return $statement[1];
                }
            }
        }

        return 'PHPRegex\\Linter\\Rule\\'.$name;
    }
}
