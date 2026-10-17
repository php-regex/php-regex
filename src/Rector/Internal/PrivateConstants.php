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

namespace PHPRegex\Rector\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use Rector\Reflection\ReflectionResolver;

/**
 * The private class constants a class reads as patterns only: a private
 * constant is read inside its class, where the rule sees every reader, so
 * its declaration is rewritten when each reader is a pattern the rule reads
 * (see PatternLiterals) written "self::NAME". A constant read any other
 * way is left as it is: by a string function, through "static::", the
 * class's name or another constant, in an attribute, by name through
 * constant(), or in a class that uses a trait, whose methods read the
 * constants of the class from another file. A public or protected constant
 * may be read from anywhere, and a global one too: they are left as well.
 * Reflection alone reads a private constant from outside its class.
 *
 * @internal
 */
final class PrivateConstants
{
    private function __construct() {}

    /**
     * The class with the value of each such constant replaced by its
     * rewrite; null when none is rewritten.
     *
     * @param \Closure(CallLike): bool  $pregCall      whether the call is one of TargetRange::FUNCTIONS
     * @param \Closure(CallLike): bool  $callbackArray whether the call is preg_replace_callback_array()
     * @param \Closure(string): ?string $rewrite       the rewritten pattern, or null to leave it
     */
    public static function rewrite(Class_ $class, \Closure $pregCall, \Closure $callbackArray, ReflectionResolver $reflection, \Closure $rewrite): ?Class_
    {
        $constants = [];
        foreach ($class->getConstants() as $declaration) {
            if (!$declaration->isPrivate()) {
                continue;
            }

            foreach ($declaration->consts as $constant) {
                if ($constant->value instanceof String_) {
                    $constants[$constant->name->toString()] = [$constant, $constant->value];
                }
            }
        }

        if ([] === $constants || [] !== $class->getTraitUses()) {
            return null;
        }

        $readers = ['byName' => false, 'all' => [], 'patterns' => []];
        foreach ($class->stmts as $statement) {
            self::collect($statement, $pregCall, $callbackArray, $reflection, $readers);
        }

        if ($readers['byName']) {
            return null;
        }

        $changed = false;
        foreach ($constants as $name => [$constant, $value]) {
            if (!self::readAsPatternsOnly($name, $readers['all'], $readers['patterns'])) {
                continue;
            }

            $rewritten = $rewrite($value->value);
            if (null !== $rewritten) {
                $constant->value = PregCall::string($rewritten);
                $changed = true;
            }
        }

        return $changed ? $class : null;
    }

    /**
     * Whether the constant is read, and only where a pattern goes.
     *
     * @param array<string, list<int>> $all      the readers of each name, by object id
     * @param array<int, true>         $patterns the "self::NAME" read where a pattern goes, by object id
     */
    private static function readAsPatternsOnly(string $name, array $all, array $patterns): bool
    {
        $readers = $all[$name] ?? [];
        foreach ($readers as $reader) {
            if (!isset($patterns[$reader])) {
                return false;
            }
        }

        return [] !== $readers;
    }

    /**
     * Records every class constant the node reads, by name, and those read
     * as "self::NAME" where a pattern goes; a nested class reads its own.
     *
     * @param \Closure(CallLike): bool                                                       $pregCall
     * @param \Closure(CallLike): bool                                                       $callbackArray
     * @param array{byName: bool, all: array<string, list<int>>, patterns: array<int, true>} $readers
     */
    private static function collect(Node $node, \Closure $pregCall, \Closure $callbackArray, ReflectionResolver $reflection, array &$readers): void
    {
        if ($node instanceof Class_) {
            return;
        }

        if ($node instanceof ClassConstFetch) {
            if ($node->name instanceof Identifier) {
                $readers['all'][$node->name->toString()][] = spl_object_id($node);
            } else {
                $readers['byName'] = true;
            }
        }

        if ($node instanceof FuncCall && (!$node->name instanceof Name || 'constant' === strtr($node->name->getLast(), 'CONSTANT', 'constant'))) {
            $readers['byName'] = true;
        }

        if ($node instanceof CallLike) {
            foreach (PatternLiterals::positions($node, $pregCall($node), $callbackArray($node), $reflection) as [$value]) {
                if ($value instanceof ClassConstFetch && $value->class instanceof Name && 'self' === $value->class->toLowerString() && $value->name instanceof Identifier) {
                    $readers['patterns'][spl_object_id($value)] = true;
                }
            }
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};
            foreach (\is_array($subNode) ? $subNode : [$subNode] as $child) {
                if ($child instanceof Node) {
                    self::collect($child, $pregCall, $callbackArray, $reflection, $readers);
                }
            }
        }
    }
}
