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

namespace PHPRegex\Linter\Extraction;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

/**
 * Reads, from a syntax tree whose names are resolved, what the file proves
 * about its constants, properties and local variables, for
 * InFileValueResolver; and turns an expression into the form that class
 * reads.
 *
 * It marks each variable with the function body it belongs to and each
 * class constant or property fetch with the class around it, so that an
 * expression read later, at a call, is translated in its own context.
 *
 * @internal
 */
final class AstValueCollector extends NodeVisitorAbstract
{
    private const SCOPE = 'phpRegexScope';

    private const CLASS_KEY = 'phpRegexClass';

    private const BOUND = 'phpRegexBound';

    private readonly InFileValueResolver $values;

    /**
     * @var list<int|null> the function bodies around the node; null for a class body or the file
     */
    private array $scopes = [];

    /**
     * @var list<array{string|null, bool, bool, string|null}> the classes around the node: the lowercase name,
     *                                                        null for a trait or an anonymous class, whether it is
     *                                                        final, whether it extends another, and the lowercase
     *                                                        name a trait use is recorded under, a trait's own
     *                                                        included
     */
    private array $classes = [];

    /**
     * @var list<bool> whether each body around the node is a closure or an arrow function, which
     *                 Closure::bind() may give another class and another $this
     */
    private array $bound = [];

    /**
     * @var list<bool> whether each body around the node returns by reference: a generator of
     *                 function &name() yields its variables by reference
     */
    private array $references = [];

    private int $nextScope = 0;

    /**
     * @param \Closure(Node): list<Arg> $patternArguments the arguments of a call that carry its pattern: a
     *                                                    variable passed there is read, not written
     */
    public function __construct(private readonly \Closure $patternArguments)
    {
        $this->values = new InFileValueResolver();
    }

    public function values(): InFileValueResolver
    {
        return $this->values;
    }

    /**
     * @return null
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof ClassLike) {
            $this->enterClass($node);
        } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure || $node instanceof ArrowFunction) {
            $this->enterFunction($node);
        } elseif ($node instanceof Variable) {
            $node->setAttribute(self::SCOPE, $this->scope());
            // $$name, ${'name'}: any variable of the body.
            if (!\is_string($node->name)) {
                $this->bailScope();
            }
        } elseif ($node instanceof ClassConstFetch || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            $node->setAttribute(self::CLASS_KEY, $this->classKey());
            $node->setAttribute(self::BOUND, [] !== $this->bound && $this->bound[\count($this->bound) - 1]);
        } elseif ($node instanceof StaticPropertyFetch && $node->name instanceof Expr) {
            $this->bailScope();
        } elseif ($node instanceof For_ || $node instanceof Foreach_ || $node instanceof While_ || $node instanceof Do_) {
            $scope = $this->scope();
            if (null !== $scope && self::runsAControlStructureUnbraced($node)) {
                // As the tokenizer reads it: such a loop may hold anything.
                $this->values->jumps($scope);
            } elseif (null !== $scope) {
                $this->values->loop($scope, $node->getStartFilePos(), $node->getEndFilePos());
            }
        } elseif ($node instanceof TraitUse) {
            $owner = [] === $this->classes ? null : $this->classes[\count($this->classes) - 1][3];
            if (null !== $owner) {
                foreach ($node->traits as $trait) {
                    $this->values->useTrait($owner, ValueExpr::classKey($trait->toString()));
                }
            }
        } elseif ($node instanceof Goto_) {
            $scope = $this->scope();
            if (null !== $scope) {
                $this->values->jumps($scope);
            }
        }

        return null;
    }

    /**
     * @return null
     */
    public function leaveNode(Node $node)
    {
        if ($node instanceof ClassLike) {
            array_pop($this->classes);
            array_pop($this->scopes);
            array_pop($this->bound);
            array_pop($this->references);
        } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure || $node instanceof ArrowFunction) {
            array_pop($this->scopes);
            array_pop($this->bound);
            array_pop($this->references);
        } elseif ($node instanceof Yield_ && [] !== $this->references && $this->references[\count($this->references) - 1]) {
            // The caller's foreach may write what is yielded by reference.
            $this->writeEverything($node->key);
            $this->writeEverything($node->value);
        } elseif ($node instanceof ClassConst) {
            $this->declareClassConstants($node);
        } elseif ($node instanceof Property) {
            $this->declareProperties($node);
        } elseif ($node instanceof Const_) {
            foreach ($node->consts as $const) {
                if (null !== $const->namespacedName) {
                    $this->values->declareConstant($const->namespacedName->toString(), $this->expression($const->value));
                }
            }
        } elseif ($node instanceof Assign) {
            $this->assign($node);
        } elseif ($node instanceof AssignRef) {
            $this->write($node->var);
            $this->write($node->expr);
        } elseif ($node instanceof AssignOp || $node instanceof PreInc || $node instanceof PreDec || $node instanceof PostInc || $node instanceof PostDec) {
            $this->write($node->var);
        } elseif ($node instanceof Foreach_) {
            $this->writeEverything($node->keyVar);
            $this->writeEverything($node->valueVar);
            if ($node->byRef && !$node->expr instanceof Array_) {
                $this->iterateByReference($node->expr);
            }
        } elseif ($node instanceof Catch_) {
            $this->write($node->var);
        } elseif ($node instanceof Unset_) {
            foreach ($node->vars as $variable) {
                $this->write($variable);
            }
        } elseif ($node instanceof Global_) {
            foreach ($node->vars as $variable) {
                $this->write($variable);
            }
        } elseif ($node instanceof Static_) {
            foreach ($node->vars as $variable) {
                $this->write($variable->var);
            }
        } elseif ($node instanceof ArrayItem && $node->byRef) {
            $this->write($node->value);
        } elseif ($node instanceof CallLike) {
            $this->leaveCall($node);
        } elseif ($node instanceof Eval_ || $node instanceof Include_) {
            $this->bailScope();
        }

        return null;
    }

    /**
     * The expression as InFileValueResolver reads it.
     */
    public function expression(Expr $expr): ValueExpr
    {
        if ($expr instanceof String_) {
            return ValueExpr::literal($expr->value, max(0, $expr->getStartFilePos()), $expr->getStartLine());
        }

        if ($expr instanceof Concat) {
            // Two literals are folded where the value is resolved, at the
            // start of the concatenation.
            return ValueExpr::concat([$this->expression($expr->left), $this->expression($expr->right)], max(0, $expr->getStartFilePos()), $expr->getStartLine());
        }

        if ($expr instanceof ClassConstFetch) {
            return $this->classConstant($expr);
        }

        if ($expr instanceof ConstFetch) {
            return $this->constant($expr->name);
        }

        if ($expr instanceof Variable) {
            $scope = $expr->getAttribute(self::SCOPE);

            return \is_string($expr->name) && 'this' !== $expr->name ? ValueExpr::variable(\is_int($scope) ? $scope : null, $expr->name, $expr->getStartFilePos()) : ValueExpr::unknown();
        }

        if ($expr instanceof PropertyFetch && $expr->var instanceof Variable && 'this' === $expr->var->name && $expr->name instanceof Identifier
            && true !== $expr->getAttribute(self::BOUND)) {
            $class = $expr->getAttribute(self::CLASS_KEY);

            return ValueExpr::property(\is_string($class) ? $class : null, $expr->name->toString());
        }

        if ($expr instanceof Array_) {
            return $this->arrayOf($expr);
        }

        while ($expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            $expr = $expr->expr;
        }

        return $expr instanceof Int_ || $expr instanceof Float_ ? ValueExpr::nonString() : ValueExpr::unknown();
    }

    private function enterClass(ClassLike $class): void
    {
        $key = null;
        $final = false;
        $owner = null === $class->namespacedName ? null : ValueExpr::classKey($class->namespacedName->toString());
        if ($class instanceof Trait_) {
            if (null !== $owner) {
                $this->values->declareTrait($owner);
            }
        } elseif (null !== $owner) {
            $key = $owner;
            $final = $class instanceof Enum_ || ($class instanceof Class_ && $class->isFinal());
            $this->values->declareClass($key, $final);
        }

        $this->classes[] = [$key, $final, $class instanceof Class_ && null !== $class->extends, $owner];
        $this->scopes[] = null;
        $this->bound[] = false;
        $this->references[] = false;
    }

    private function enterFunction(Function_|ClassMethod|Closure|ArrowFunction $function): void
    {
        $outer = $this->scope();
        $scope = $this->nextScope++;
        $this->scopes[] = $scope;
        $this->bound[] = $function instanceof Closure || $function instanceof ArrowFunction;
        $this->references[] = $function->byRef;

        foreach ($function->params as $param) {
            if ($param->var instanceof Variable && \is_string($param->var->name)) {
                $this->values->bail($scope, $param->var->name);
            }
        }

        if (!$function instanceof Closure) {
            return;
        }

        // A closure use is always a plain variable: use ($re), use (&$re).
        foreach ($function->uses as $use) {
            $name = \is_string($use->var->name) ? $use->var->name : '';
            $this->values->bail($scope, $name);
            // use (&$re) lets the closure write the variable of the body around it.
            if ($use->byRef && null !== $outer) {
                $this->values->bail($outer, $name);
            }
        }
    }

    private function declareClassConstants(ClassConst $constants): void
    {
        $class = $this->classKey();
        if (null === $class) {
            return;
        }

        foreach ($constants->consts as $const) {
            $this->values->declareClassConstant($class, $const->name->toString(), $this->expression($const->value), $constants->isFinal());
        }
    }

    private function declareProperties(Property $property): void
    {
        // A property is declared in a class, which enterClass() put there.
        [$key, $final, $extends] = end($this->classes) ?: [null, false, false, null];
        if (null === $key) {
            return;
        }

        // Another file may write a public property, a subclass replace one
        // of an open class, a parent class write a protected one; a hook
        // computes its own value.
        $proves = ($property->isPrivate() ? $final || $property->isFinal() : $property->isProtected() && $final && !$extends)
            && !$property->isStatic() && [] === $property->hooks;

        foreach ($property->props as $item) {
            $this->values->declareProperty($key, $item->name->toString(), $proves && null !== $item->default ? $this->expression($item->default) : null);
        }
    }

    private function assign(Assign $assign): void
    {
        $target = $assign->var;
        if ($target instanceof Variable && \is_string($target->name)) {
            $scope = $target->getAttribute(self::SCOPE);
            if (\is_int($scope)) {
                // A local array of literals proves no pattern either: it is
                // not built.
                $this->values->assign($scope, $target->name, $assign->expr instanceof Array_ ? ValueExpr::unknown() : $this->expression($assign->expr), $target->getStartFilePos());
            }

            return;
        }

        $this->write($target);
    }

    private function leaveCall(CallLike $call): void
    {
        if ($call instanceof FuncCall && $call->name instanceof Name) {
            // Resolved, a name of one segment is the global function, or the
            // namespace's one PHP falls back from: Foo\extract() is neither.
            $name = $call->name->toLowerString();
            if ('extract' === $name || 'compact' === $name) {
                $this->bailScope();
            }

            if ('define' === $name) {
                $this->define($call);
            }

            // array_walk() over an object writes each of its properties,
            // private ones included, from any scope.
            if (('array_walk' === $name || 'array_walk_recursive' === $name) && !self::walked($call) instanceof Array_) {
                $this->values->writeAnyProperty(null);
            }
        }

        // Any call may take a variable by reference: the file does not say
        // which. The argument the call reads its pattern from is read.
        $read = ($this->patternArguments)($call);
        foreach ($call->getRawArgs() as $arg) {
            if (!$arg instanceof Arg || \in_array($arg, $read, true)) {
                continue;
            }

            // $this passed: the callee may write any property of it, as
            // array_walk($this, ...) does, private ones included.
            if ($arg->value instanceof Variable && 'this' === $arg->value->name) {
                $this->values->writeAnyProperty($this->classKey());
            } elseif (self::isChain($arg->value)) {
                $this->write($arg->value);
            }
        }
    }

    /**
     * Whether a for, foreach or while loop runs, without braces, a control
     * structure: the loop ends where that statement does.
     */
    private static function runsAControlStructureUnbraced(For_|Foreach_|While_|Do_ $loop): bool
    {
        $body = 1 === \count($loop->stmts) && !$loop instanceof Do_ ? $loop->stmts[0] : null;

        return ($body instanceof If_ || $body instanceof For_ || $body instanceof Foreach_ || $body instanceof While_ || $body instanceof Do_
            || $body instanceof Switch_ || $body instanceof TryCatch || $body instanceof Declare_) && $body->getEndFilePos() === $loop->getEndFilePos();
    }

    /**
     * What array_walk() walks: its first argument, or the one named
     * "array"; null after a spread or with none.
     */
    private static function walked(FuncCall $call): ?Expr
    {
        foreach ($call->getRawArgs() as $position => $arg) {
            if ($arg instanceof Arg && ((0 === $position && null === $arg->name && !$arg->unpack) || 'array' === $arg->name?->toString())) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * define('NAME', value), the name a literal.
     */
    private function define(FuncCall $call): void
    {
        $args = $call->getRawArgs();
        $first = $args[0] ?? null;
        $second = $args[1] ?? null;
        if (2 !== \count($args) || !$first instanceof Arg || !$second instanceof Arg) {
            return;
        }

        foreach ([$first, $second] as $arg) {
            if (null !== $arg->name || $arg->unpack || $arg->byRef) {
                return;
            }
        }

        $name = $first->value;
        if ($name instanceof String_ && \in_array($name->getAttribute('kind'), [String_::KIND_SINGLE_QUOTED, String_::KIND_DOUBLE_QUOTED], true) && '' !== $name->value) {
            $this->values->declareConstant($name->value, $this->expression($second->value));
        }
    }

    /**
     * Mark what an expression written to may change: the variable at its
     * root, the property names along it.
     */
    private function write(?Expr $target): void
    {
        while (null !== $target) {
            if ($target instanceof Variable) {
                $scope = $target->getAttribute(self::SCOPE);
                if (\is_string($target->name) && \is_int($scope)) {
                    $this->values->bail($scope, $target->name);
                }

                return;
            }

            if ($target instanceof PropertyFetch || $target instanceof NullsafePropertyFetch) {
                $this->writeMember($target);
                $target = $target->var;

                continue;
            }

            if ($target instanceof ArrayDimFetch) {
                $target = $target->var;

                continue;
            }

            if ($target instanceof List_ || $target instanceof Array_) {
                $this->writeEverything($target);
            }

            return;
        }
    }

    /**
     * Mark every variable and property name of a destructuring or a
     * foreach target.
     */
    private function writeEverything(?Node $node): void
    {
        if (null === $node) {
            return;
        }

        if ($node instanceof Variable) {
            $scope = $node->getAttribute(self::SCOPE);
            if (\is_string($node->name) && \is_int($scope)) {
                $this->values->bail($scope, $node->name);
            }
        } elseif ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            $this->writeMember($node);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            foreach (\is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    $this->writeEverything($item);
                }
            }
        }
    }

    /**
     * What a by-reference foreach over the expression writes: a named
     * property, that property; anything else may be an object, which has
     * each property its scope sees written, under no name: in a class
     * body, the class's own.
     */
    private function iterateByReference(Expr $expr): void
    {
        if (($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) && $expr->name instanceof Identifier) {
            $this->write($expr);
        } elseif ([] !== $this->classes) {
            $this->values->writeAnyProperty($this->classKey());
        }
    }

    /**
     * The property a fetch writes: its name, or, under a computed name
     * ($o->$name, $o->{'re'}), any property of the class around.
     */
    private function writeMember(PropertyFetch|NullsafePropertyFetch $fetch): void
    {
        if ($fetch->name instanceof Identifier) {
            $this->values->writeProperty($fetch->name->toString());

            return;
        }

        $class = $fetch->getAttribute(self::CLASS_KEY);
        $this->values->writeAnyProperty(\is_string($class) ? $class : null);
    }

    /**
     * Whether the expression is a variable, or a property or an offset,
     * whatever its receiver: what a call may take by reference.
     */
    private static function isChain(Expr $expr): bool
    {
        return ($expr instanceof Variable && \is_string($expr->name))
            || $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch || $expr instanceof ArrayDimFetch;
    }

    private function classConstant(ClassConstFetch $fetch): ValueExpr
    {
        if (!$fetch->class instanceof Name || !$fetch->name instanceof Identifier || 'class' === $fetch->name->toLowerString()) {
            return ValueExpr::unknown();
        }

        $original = $fetch->class->getAttribute('originalName');
        $written = ($original instanceof Name ? $original : $fetch->class)->toCodeString().'::'.$fetch->name->toString();
        // A closure may be bound to another class: self:: names none.
        $context = $fetch->getAttribute(self::CLASS_KEY);
        $context = \is_string($context) && true !== $fetch->getAttribute(self::BOUND) ? $context : null;

        return match ($fetch->class->toLowerString()) {
            'self' => ValueExpr::classConstant($context, $fetch->name->toString(), false, $written),
            'static' => ValueExpr::classConstant($context, $fetch->name->toString(), true, $written),
            'parent' => ValueExpr::unknown(),
            default => ValueExpr::classConstant(ValueExpr::classKey($fetch->class->toString()), $fetch->name->toString(), false, $written),
        };
    }

    private function constant(Name $name): ValueExpr
    {
        $lower = $name->toLowerString();
        if ('true' === $lower || 'false' === $lower) {
            return ValueExpr::nonString();
        }

        if ('null' === $lower) {
            return ValueExpr::unknown();
        }

        $original = $name->getAttribute('originalName');
        $written = ($original instanceof Name ? $original : $name)->toCodeString();

        // An unqualified name in a namespace: the namespace's constant, then
        // the global one.
        $namespaced = $name->getAttribute('namespacedName');
        $names = $namespaced instanceof Name ? [$namespaced->toString(), $name->toString()] : [$name->toString()];

        return ValueExpr::constant(array_map(ValueExpr::constantKey(...), $names), $written);
    }

    private function arrayOf(Array_ $array): ValueExpr
    {
        $items = [];
        foreach ($array->items as $item) {
            if (null === $item || $item->byRef || $item->unpack) {
                return ValueExpr::unknown();
            }

            $items[] = [null === $item->key ? null : $this->expression($item->key), $this->expression($item->value)];
        }

        return ValueExpr::arrayOf($items);
    }

    private function scope(): ?int
    {
        return [] === $this->scopes ? null : $this->scopes[\count($this->scopes) - 1];
    }

    private function classKey(): ?string
    {
        return [] === $this->classes ? null : $this->classes[\count($this->classes) - 1][0];
    }

    private function bailScope(): void
    {
        $scope = $this->scope();
        if (null !== $scope) {
            $this->values->bailScope($scope);
        }
    }
}
