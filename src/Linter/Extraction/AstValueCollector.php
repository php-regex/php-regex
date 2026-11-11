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
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
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

    private const CALLS = 'phpRegexCalls';

    private const HELD = 'phpRegexHeld';

    private const RUNS_AT = 'phpRegexRunsAt';

    private const CERTAIN = 'phpRegexCertain';

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
     * @var list<list<string>|false|null> for each body around the node: false for one that is no closure; for a
     *                                    closure or an arrow function, which Closure::bind() may give another
     *                                    class and another $this, the lowercase functions the call it is
     *                                    written in may reach, when PHP's own only calls it, or null
     */
    private array $held = [];

    /**
     * @var list<list<string>> the functions a call a closure is written in may reach, for each such closure
     */
    private array $holders = [];

    /**
     * @var list<list<list<string>>> for each class constant read in closures PHP's functions alone may hold, the
     *                               functions the call of each closure may reach
     */
    private array $closureCalls = [];

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
     * @param array<Node> $nodes
     *
     * @return null
     */
    public function beforeTraverse(array $nodes)
    {
        self::markStatements($nodes);

        return null;
    }

    /**
     * @return null
     */
    public function enterNode(Node $node)
    {
        // A closure written in the call is entered after it.
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $this->holdClosures($node, $node->name);
        }

        if ($node instanceof ClassLike) {
            $this->enterClass($node);
        } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure || $node instanceof ArrowFunction) {
            $this->enterFunction($node);
        } elseif ($node instanceof Variable) {
            $node->setAttribute(self::SCOPE, $this->scope());
            // $$name, ${'name'}: any variable of the body.
            if (!\is_string($node->name)) {
                $this->bailScope();
            } elseif ('GLOBALS' === $node->name) {
                $this->values->nameGlobals();
            }
        } elseif ($node instanceof ClassConstFetch || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            $node->setAttribute(self::CLASS_KEY, $this->classKey());
            $bound = [] !== $this->held && false !== $this->held[\count($this->held) - 1];
            $node->setAttribute(self::BOUND, $bound);
            // Out of any closure, the class is the one around the read.
            $calls = $bound && $node instanceof ClassConstFetch ? $this->callsAround() : null;
            if (null !== $calls) {
                $node->setAttribute(self::CALLS, \count($this->closureCalls));
                $this->closureCalls[] = $calls;
            }
        } elseif ($node instanceof StaticPropertyFetch && $node->name instanceof Expr) {
            $this->bailScope();
        } elseif ($node instanceof For_ || $node instanceof Foreach_ || $node instanceof While_ || $node instanceof Do_) {
            $scope = $this->scope();
            if (null !== $scope && self::runsAControlStructureUnbraced($node)) {
                // As the tokenizer reads it: such a loop may hold anything.
                $this->values->jumps($scope);
            } elseif (null !== $scope) {
                // What a foreach iterates is read once, before its first
                // turn: the loop runs again from its "as".
                $this->values->loop($scope, $node instanceof Foreach_ ? $node->expr->getEndFilePos() + 1 : $node->getStartFilePos(), $node->getEndFilePos());
                // What a for runs after each turn is written in its head,
                // and runs after its body.
                foreach ($node instanceof For_ ? (new NodeFinder())->findInstanceOf($node->loop, Assign::class) : [] as $assign) {
                    $assign->setAttribute(self::RUNS_AT, $node->getEndFilePos());
                }
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
            array_pop($this->held);
            array_pop($this->references);
        } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure || $node instanceof ArrowFunction) {
            array_pop($this->scopes);
            array_pop($this->held);
            array_pop($this->references);
        } elseif ($node instanceof Yield_ && [] !== $this->references && $this->references[\count($this->references) - 1]) {
            // The caller's foreach may write what is yielded by reference.
            $this->writeEverything($node->key);
            $this->writeEverything($node->value);
        } elseif ($node instanceof ClassConst) {
            $this->declareClassConstants($node);
        } elseif ($node instanceof EnumCase) {
            $this->declareEnumCase($node);
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
            $this->leaveForeach($node);
        } elseif ($node instanceof Catch_) {
            $this->write($node->var);
        } elseif ($node instanceof Unset_) {
            foreach ($node->vars as $variable) {
                $this->write($variable);
            }
        } elseif ($node instanceof Global_) {
            $this->values->nameGlobals();
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

        // Kind::Re->value: the backing value of an enum case.
        if ($expr instanceof PropertyFetch && $expr->var instanceof ClassConstFetch && $expr->name instanceof Identifier && 'value' === $expr->name->toString()) {
            return ValueExpr::enumCaseValue($this->classConstant($expr->var));
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

        // $map['word'], self::LIST[0]: an item of an array a variable or a
        // constant holds. An item of an item, or of a property, is not
        // followed.
        if ($expr instanceof ArrayDimFetch) {
            $array = $expr->var;
            if (null === $expr->dim || (!$array instanceof Variable && !$array instanceof ConstFetch && !$array instanceof ClassConstFetch)) {
                return ValueExpr::unknown();
            }

            $held = $this->expression($array);

            return \in_array($held->kind, [ValueExpr::VARIABLE, ValueExpr::CONSTANT, ValueExpr::CLASS_CONSTANT], true)
                ? ValueExpr::item($held, $this->key($expr->dim), max(0, $expr->getStartFilePos()), $expr->getStartLine())
                : ValueExpr::unknown();
        }

        if ($expr instanceof FuncCall) {
            return $this->callTo($expr);
        }

        $offset = max(0, $expr->getStartFilePos());

        // "/^{$part}$/", a heredoc that interpolates: the concatenation of
        // its pieces, which the parser decodes, and of its expressions.
        if ($expr instanceof InterpolatedString) {
            $parts = [];
            foreach ($expr->parts as $part) {
                $parts[] = $part instanceof InterpolatedStringPart
                    ? ValueExpr::literal($part->value, max(0, $part->getStartFilePos()), $part->getStartLine())
                    : $this->expression($part);
            }

            return ValueExpr::concat($parts, $offset, $expr->getStartLine());
        }

        // The set of the two arms, whatever the condition; or "A ?: B".
        if ($expr instanceof Ternary) {
            return null === $expr->if
                ? ValueExpr::fallback($this->expression($expr->cond), $this->expression($expr->else), false, $offset, $expr->getStartLine())
                : ValueExpr::setOf([$this->expression($expr->if), $this->expression($expr->else)], $offset, $expr->getStartLine());
        }

        if ($expr instanceof Coalesce) {
            return ValueExpr::fallback($this->expression($expr->left), $this->expression($expr->right), true, $offset, $expr->getStartLine());
        }

        // The set of the values of the arms, the default one among them.
        if ($expr instanceof Match_) {
            $arms = [];
            foreach ($expr->arms as $arm) {
                $arms[] = $this->expression($arm->body);
            }

            return ValueExpr::setOf($arms, $offset, $expr->getStartLine());
        }

        // -1, +2.5, - -3: a number under its signs. An integer keeps its
        // value.
        $negative = false;
        while ($expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            $negative = $negative !== $expr instanceof UnaryMinus;
            $expr = $expr->expr;
        }

        if ($expr instanceof Int_) {
            return ValueExpr::nonString($negative ? -$expr->value : $expr->value);
        }

        return $expr instanceof Float_ ? ValueExpr::nonString() : ValueExpr::unknown();
    }

    /**
     * An expression that names an item of an array, between the brackets
     * of a read or before the "=>" of a literal. PHP casts a key that is
     * no string and no integer: true and false are 1 and 0, null the empty
     * string, a float its integer part.
     */
    private function key(Expr $key): ValueExpr
    {
        $offset = max(0, $key->getStartFilePos());
        if ($key instanceof ConstFetch) {
            $lower = $key->name->toLowerString();
            if ('true' === $lower || 'false' === $lower) {
                return ValueExpr::castKey('true' === $lower, $offset, $key->getStartLine());
            }

            if ('null' === $lower) {
                return ValueExpr::castKey(null, $offset, $key->getStartLine());
            }
        }

        $negative = false;
        $number = $key;
        while ($number instanceof UnaryMinus || $number instanceof UnaryPlus) {
            $negative = $negative !== $number instanceof UnaryMinus;
            $number = $number->expr;
        }

        return $number instanceof Float_
            ? ValueExpr::castKey($negative ? -$number->value : $number->value, $offset, $key->getStartLine())
            : $this->expression($key);
    }

    /**
     * A call to a function by its name: preg_quote() is a quoted text;
     * sprintf(), implode() and join() are read with their arguments, each
     * given by its position. Any other call is not followed.
     */
    private function callTo(FuncCall $call): ValueExpr
    {
        $names = $call->name instanceof Name ? self::functionNames($call->name) : [];
        $name = $names[\count($names) - 1] ?? '';
        if (InFileValueResolver::QUOTING === $name) {
            return $this->quotedText($call, $names);
        }

        $joins = isset(InFileValueResolver::JOINING[$name]);
        if (InFileValueResolver::FORMATTING !== $name && !$joins) {
            return ValueExpr::unknown();
        }

        $arguments = [];
        foreach ($call->getRawArgs() as $arg) {
            // sprintf(...) is a closure; a spread or a named argument
            // fills places the call does not spell out.
            if (!$arg instanceof Arg || null !== $arg->name || $arg->unpack) {
                return ValueExpr::unknown();
            }

            $arguments[] = $this->expression($arg->value);
        }

        $offset = max(0, $call->getStartFilePos());
        if ([] === $arguments || ($joins && \count($arguments) > 2)) {
            return ValueExpr::unknown();
        }

        return $joins
            ? ValueExpr::join($arguments, $names, $offset, $call->getStartLine())
            : ValueExpr::format($arguments, $names, $offset, $call->getStartLine());
    }

    /**
     * A call to preg_quote(): a quoted text, with the delimiter its second
     * argument gives, by position or by name, when that is a string
     * literal.
     *
     * @param list<string> $names the lowercase functions the call may reach
     */
    private function quotedText(FuncCall $call, array $names): ValueExpr
    {
        $position = 0;
        $positional = null;
        $named = null;
        foreach ($call->getRawArgs() as $arg) {
            // preg_quote(...) is a closure, not a text.
            if (!$arg instanceof Arg) {
                return ValueExpr::unknown();
            }

            if (null !== $arg->name) {
                if ('delimiter' === $arg->name->toString()) {
                    $named ??= $arg;
                }
            } elseif ($arg->unpack) {
                // A spread makes every later position unknowable; a name
                // still passes the argument.
                $position = \PHP_INT_MIN;
            } elseif (1 === $position++) {
                $positional ??= $arg;
            }
        }

        $delimiter = ($positional ?? $named)?->value;

        return ValueExpr::hole(true, $delimiter instanceof String_ ? $delimiter->value : null, '', $names);
    }

    /**
     * The lowercase functions a call by the name may reach, in the order
     * PHP tries them: the namespace's own, then the global one.
     *
     * @return non-empty-list<string>
     */
    private static function functionNames(Name $name): array
    {
        $namespaced = $name->getAttribute('namespacedName');

        return $namespaced instanceof Name ? [$namespaced->toLowerString(), $name->toLowerString()] : [$name->toLowerString()];
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
        $this->held[] = false;
        $this->references[] = false;
    }

    private function enterFunction(Function_|ClassMethod|Closure|ArrowFunction $function): void
    {
        // A function of the namespace: a call by its name reaches it before
        // PHP's own.
        if ($function instanceof Function_ && null !== $function->namespacedName) {
            $this->values->declareFunction($function->namespacedName->toString());
        }

        if (!$function instanceof ArrowFunction) {
            self::markStatements($function->getStmts() ?? []);
        }

        $outer = $this->scope();
        $scope = $this->nextScope++;
        $this->scopes[] = $scope;
        $holder = $function->getAttribute(self::HELD);
        $this->held[] = $function instanceof Closure || $function instanceof ArrowFunction ? (\is_int($holder) ? $this->holders[$holder] ?? null : null) : false;
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

    /**
     * Marks the assignments that stand as statements of a function body
     * itself, or of the file, those of a namespace and of a block written
     * among them included: every path to what follows one runs it.
     *
     * @param array<Node> $statements
     */
    private static function markStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                $statement->expr->setAttribute(self::CERTAIN, true);
            } elseif ($statement instanceof Block || $statement instanceof Namespace_) {
                self::markStatements($statement->stmts);
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

    /**
     * A case of a backed enum; a pure enum's case, which has no value,
     * declares nothing.
     */
    private function declareEnumCase(EnumCase $case): void
    {
        $enum = $this->classKey();
        if (null === $enum || null === $case->expr) {
            return;
        }

        $this->values->declareEnumCase($enum, $case->name->toString(), $this->expression($case->expr));
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
                $runsAt = $assign->getAttribute(self::RUNS_AT);
                $this->values->assign(
                    $scope,
                    $target->name,
                    $this->expression($assign->expr),
                    \is_int($runsAt) ? $runsAt : $target->getStartFilePos(),
                    \is_int($runsAt) ? $runsAt : $assign->getEndFilePos(),
                    true === $assign->getAttribute(self::CERTAIN),
                );
            }

            return;
        }

        $this->write($target);
    }

    private function leaveCall(CallLike $call): void
    {
        // The code a call of the file level runs may write its variables.
        if ([] === $this->scopes) {
            $this->values->call($call->getEndFilePos());
        }

        if ($call instanceof FuncCall && $call->name instanceof Name) {
            // Resolved, a name of one segment is the global function, or the
            // namespace's one PHP falls back from: Foo\extract() is neither.
            $name = $call->name->toLowerString();
            if ('extract' === $name || 'compact' === $name) {
                $this->bailScope();
            }

            if (InFileValueResolver::DEFINING === $name) {
                $this->define($call, self::functionNames($call->name));
            }

            // array_walk() over an object writes each of its properties,
            // private ones included, from any scope.
            if (('array_walk' === $name || 'array_walk_recursive' === $name) && !self::walked($call) instanceof Array_) {
                $this->values->writeAnyProperty(null);
            }
        }

        // The functions the call may reach, when PHP's own takes every
        // argument by value: what is passed to it is not written.
        $byValue = $call instanceof FuncCall && $call->name instanceof Name ? self::functionNames($call->name) : [];
        if (!isset(InFileValueResolver::BY_VALUE[$byValue[\count($byValue) - 1] ?? ''])) {
            $byValue = [];
        }

        // Any other call may take a variable by reference: the file does
        // not say which. The argument the call reads its pattern from is
        // read.
        $read = ($this->patternArguments)($call);
        foreach ($call->getRawArgs() as $arg) {
            if (!$arg instanceof Arg || \in_array($arg, $read, true)) {
                continue;
            }

            // $this passed: the callee may write any property of it, as
            // array_walk($this, ...) does, private ones included.
            $value = $arg->value;
            if ($value instanceof Variable && 'this' === $value->name) {
                $this->values->writeAnyProperty($this->classKey());
            } elseif (self::isChain($value) && [] === $byValue) {
                $this->write($value, $call->getEndFilePos());
            } elseif (self::isChain($value)) {
                $this->values->byValue($byValue, fn () => $this->write($value, $call->getEndFilePos()));
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
     * define('NAME', value), the name a literal: a constant when the call
     * reaches the function of PHP.
     *
     * @param list<string> $names the lowercase functions the call may reach
     */
    private function define(FuncCall $call, array $names): void
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
            $this->values->define($names, $name->value, $this->expression($second->value));
        }
    }

    /**
     * Mark what an expression written to may change: the variable at its
     * root, the property names along it.
     *
     * @param int|null $at where the call the expression is passed to ends, when a call may write it
     */
    private function write(?Expr $target, ?int $at = null): void
    {
        while (null !== $target) {
            if ($target instanceof Variable) {
                $scope = $target->getAttribute(self::SCOPE);
                if (\is_string($target->name) && \is_int($scope)) {
                    $this->values->bail($scope, $target->name, $at);
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

            return;
        }

        // A variable, or what one leads to: the loop writes its items.
        if (self::isChain($expr)) {
            $this->write($expr);
        }

        if ([] !== $this->classes) {
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
        // A closure may be bound to another class: self:: names none,
        // unless a function of PHP that only calls it holds each closure
        // around the read.
        $index = $fetch->getAttribute(self::CALLS);
        $calls = true === $fetch->getAttribute(self::BOUND) ? (\is_int($index) ? $this->closureCalls[$index] ?? null : null) : [];
        $context = $fetch->getAttribute(self::CLASS_KEY);
        $context = \is_string($context) && null !== $calls ? $context : null;
        $calls = null === $context ? [] : $calls ?? [];

        return match ($fetch->class->toLowerString()) {
            'self' => ValueExpr::classConstant($context, $fetch->name->toString(), false, $written, $calls),
            'static' => ValueExpr::classConstant($context, $fetch->name->toString(), true, $written, $calls),
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

            $items[] = [null === $item->key ? null : $this->key($item->key), $this->expression($item->value)];
        }

        return ValueExpr::arrayOf($items);
    }

    /**
     * The function body around the node, or the file level; null in a
     * class body.
     */
    private function scope(): ?int
    {
        return [] === $this->scopes ? InFileValueResolver::FILE : $this->scopes[\count($this->scopes) - 1];
    }

    /**
     * The functions the call of each closure around the node may reach,
     * from the innermost, up to the body that is no closure; null when
     * one of them is written in no call of a function of PHP that only
     * calls its closure: some code may bind it.
     *
     * @return list<list<string>>|null
     */
    private function callsAround(): ?array
    {
        $calls = [];
        for ($i = \count($this->held) - 1; $i >= 0; $i--) {
            $holder = $this->held[$i];
            if (false === $holder) {
                break;
            }

            if (null === $holder) {
                return null;
            }

            $calls[] = $holder;
        }

        return $calls;
    }

    /**
     * Marks the closures and the arrow functions written as a callback of
     * a call to a function of PHP that calls it and lets it go, with the
     * functions the call may reach. One written as any other argument is
     * handed to the callback, or refused: it is not marked.
     */
    private function holdClosures(FuncCall $call, Name $name): void
    {
        $names = self::functionNames($name);
        $function = $names[\count($names) - 1];
        if (!isset(InFileValueResolver::CALLING[$function])) {
            return;
        }

        // How many arguments the call passes, when each stands at its
        // position: a name or a spread leaves the last ones unknown.
        $arguments = array_values($call->getRawArgs());
        $count = \count($arguments);
        foreach ($arguments as $arg) {
            if (!$arg instanceof Arg || $arg->unpack || null !== $arg->name) {
                $count = null;

                break;
            }
        }

        foreach ($arguments as $position => $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                continue;
            }

            $value = $arg->value;
            if (($value instanceof Closure || $value instanceof ArrowFunction) && [] === $value->attrGroups
                && InFileValueResolver::callsArgument($function, null === $arg->name ? $position : null, $arg->name?->toString(), $count)) {
                $value->setAttribute(self::HELD, \count($this->holders));
                $this->holders[] = $names;
            }
        }
    }

    /**
     * A foreach, once its body is read: the head assigns a target that is
     * a plain variable at each turn, one of the values of what the loop
     * iterates, or one of its keys; one variable named as both holds the
     * key, which PHP assigns last. Any other target, one taken by
     * reference and one of a loop whose end is not known are written
     * unseen.
     */
    private function leaveForeach(Foreach_ $loop): void
    {
        $scope = $this->scope();
        // As the tokenizer reads it: such a loop may hold anything.
        $iterated = null === $scope || self::runsAControlStructureUnbraced($loop) ? null : $this->expression($loop->expr);
        $key = null;
        foreach ([[$loop->keyVar, true], [$loop->valueVar, false]] as [$target, $isKey]) {
            if (null !== $iterated && null !== $scope && $target instanceof Variable && \is_string($target->name) && 'this' !== $target->name && ($isKey || !$loop->byRef)) {
                // "as $v => $v" assigns the value, then the key: the
                // variable holds the key.
                if ($target->name === $key) {
                    continue;
                }

                $key = $isKey ? $target->name : null;
                $this->values->iterate($scope, $target->name, ValueExpr::each($iterated, $isKey), $loop->valueVar->getEndFilePos(), $loop->expr->getEndFilePos() + 1, $loop->getEndFilePos());
            } else {
                $this->writeEverything($target);
            }
        }

        if ($loop->byRef && !$loop->expr instanceof Array_) {
            $this->iterateByReference($loop->expr);
        }
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
