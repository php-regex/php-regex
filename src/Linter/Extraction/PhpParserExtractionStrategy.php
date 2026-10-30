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

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast\Array_ as ArrayCast;
use PhpParser\Node\Expr\Cast\Object_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Clone_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPRegex\Linter\PatternOccurrence;

/**
 * PhpParser-based regex pattern extraction strategy.
 *
 * This strategy uses nikic/php-parser to build an AST and extract regex
 * patterns with better accuracy than the token-based approach. That package
 * is an optional dependency — it is listed under "suggest", and installing
 * phpstan/phpstan usually brings it along — so the extractor factory falls
 * back to the tokenizer when it is absent.
 *
 * Names are resolved before extraction, so imported wrappers such as
 * composer/pcre's Preg::match() are matched on their fully qualified class
 * and a same-named class of the project's own is not mistaken for one. A
 * pattern argument that is no literal is read through the constants,
 * properties and local variables the file proves (AstValueCollector,
 * InFileValueResolver).
 *
 * @internal
 */
final class PhpParserExtractionStrategy implements ExtractorInterface, PatternFunctionAwareInterface
{
    private readonly ?Parser $parser;

    private readonly PatternFunctionRegistry $registry;

    /**
     * @param array<int, string> $customFunctions Additional functions/static methods to check (e.g., 'MyClass::customRegexCheck')
     */
    public function __construct(array $customFunctions = [], ?PatternFunctionRegistry $registry = null)
    {
        $parser = null;
        if (class_exists(ParserFactory::class)) {
            $parserFactory = new ParserFactory();
            $parser = $parserFactory->createForHostVersion();
        }

        $this->parser = $parser;
        $this->registry = ($registry ?? PatternFunctionRegistry::defaults())->withCustomFunctions($customFunctions);
    }

    public function withPatternFunctions(array $specs, array $plain = []): static
    {
        return new self([], $this->registry->withDeclaredFunctions($specs, $plain)->withDeclarationsRead());
    }

    public function customGlobalFunctions(): array
    {
        return $this->registry->customGlobalFunctions();
    }

    public function extract(array $files): array
    {
        if (empty($files)) {
            return [];
        }

        // Used on its own, the functions the files mark with #[RegexPattern]
        // join the registry for this run.
        $specs = $this->registry->declarationsRead() ? [] : PatternAttributeScanner::specs($files);
        $strategy = [] === $specs ? $this : new self([], $this->registry->withDeclaredFunctions($specs));

        return $strategy->analyzeFilesWithPhpStan($files);
    }

    /**
     * @param array<string> $files
     *
     * @return list<PatternOccurrence>
     */
    private function analyzeFilesWithPhpStan(array $files): array
    {
        $occurrences = [];

        foreach ($files as $file) {
            $fileOccurrences = $this->analyzeFileWithPhpStan($file);
            $this->appendOccurrences($occurrences, $fileOccurrences);
        }

        return $occurrences;
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function analyzeFileWithPhpStan(string $file): array
    {
        if (null === $this->parser) {
            return [];
        }

        if (!is_file($file)) {
            return [];
        }

        $content = is_readable($file) ? @file_get_contents($file) : false;
        if (false === $content) {
            return [PatternOccurrence::unread($file, 'Not linted: the file could not be read.')];
        }

        if ('' === $content || !$this->registry->matchesContent($content)) {
            return [];
        }

        if (!MemoryBudget::allows($content, MemoryBudget::PARSE_FACTOR)) {
            return [PatternOccurrence::unread($file, MemoryBudget::refusal($content, MemoryBudget::PARSE_FACTOR))];
        }

        try {
            $ast = $this->parser->parse($content);
            if (!\is_array($ast)) {
                return [];
            }

            // The names as written stay on the resolved ones: a pattern read
            // through a constant is located "via Patterns::RE", as the call
            // spells it.
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
            $ast = $traverser->traverse($ast);
        } catch (Error|\RangeException $e) {
            // A file PHP cannot parse still holds patterns: the tokenizer
            // reads them, and the run counts the file. A RangeException is
            // the parser meeting a token of a PHP newer than itself. Anything
            // else thrown here is a defect of ours, and is not caught.
            return [
                PatternOccurrence::parserFallback($file, $e->getMessage()),
                ...(new TokenBasedExtractionStrategy([], $this->registry))->extract([$file]),
            ];
        }

        $found = [];
        foreach ($ast as $node) {
            $this->extractFromNode($node, $file, $content, $found);
        }

        // Each pattern judged for the versions the guards around it keep.
        return PhpParserGuards::regions($ast, $content)->apply($this->settle($found, $ast, $file, $content));
    }

    /**
     * The occurrences in the order of their calls. A pattern no literal
     * gives is read, when the file holds one, through what the whole file
     * proves about its names.
     *
     * @param list<PatternOccurrence|PendingPattern<Expr>> $found
     * @param array<Node>                                  $ast
     *
     * @return list<PatternOccurrence>
     */
    private function settle(array $found, array $ast, string $file, string $content): array
    {
        $pending = array_filter($found, static fn (PatternOccurrence|PendingPattern $item): bool => $item instanceof PendingPattern);
        if ([] === $pending) {
            return array_values(array_filter($found, static fn (PatternOccurrence|PendingPattern $item): bool => $item instanceof PatternOccurrence));
        }

        $collector = new AstValueCollector($this->patternArguments(...));
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);
        $values = $collector->values();

        // Every read is known before the first is resolved: a value
        // assigned after the last never reaches any.
        $expressions = [];
        foreach ($pending as $index => $item) {
            $expressions[$index] = $collector->expression($item->place);
            $values->read($expressions[$index]);
        }

        $occurrences = [];
        foreach ($found as $index => $item) {
            if ($item instanceof PatternOccurrence) {
                $occurrences[] = $item;

                continue;
            }

            array_push($occurrences, ...$item->occurrences($values, $expressions[$index], $file, $content));
        }

        return $occurrences;
    }

    /**
     * @param list<PatternOccurrence|PendingPattern<Expr>> $found
     */
    private function extractFromNode(Node $node, string $file, string $content, array &$found): void
    {
        if ($node instanceof FuncCall) {
            array_push($found, ...$this->extractFromFuncCall($node, $file, $content));
        } else {
            $match = $this->patternCall($node);
            if (null !== $match) {
                $this->extractFromArgs($match[1], $match[0], $file, $content, $found);
            }
        }

        // Recursively check child nodes
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};
            if ($subNode instanceof Node) {
                $this->extractFromNode($subNode, $file, $content, $found);
            } elseif (\is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->extractFromNode($item, $file, $content, $found);
                    }
                }
            }
        }
    }

    /**
     * The patterns of a function call.
     *
     * @return list<PatternOccurrence|PendingPattern<Expr>>
     */
    private function extractFromFuncCall(FuncCall $funcCall, string $file, string $content): array
    {
        $found = [];
        $match = $this->patternCall($funcCall);
        if (null !== $match) {
            $this->extractFromArgs($match[1], $match[0], $file, $content, $found);
        }

        return $found;
    }

    /**
     * The pattern function a node calls, with its arguments: a function, a
     * static method, a method of PHPUnit's on $this, a constructor or an
     * attribute.
     *
     * @return array{PatternFunction, list<Arg>}|null
     */
    private function patternCall(Node $node): ?array
    {
        $function = null;
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            // An unqualified name in a namespace: PHP calls the namespace's
            // function first, the global one when there is none.
            $namespaced = $node->name->getAttribute('namespacedName');
            $function = $this->registry->lookupCall($namespaced instanceof Name ? $namespaced->toString() : null, $node->name->toString());
        } elseif ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $function = $node->class->isSpecialClassName()
                ? $this->registry->lookupNamedMethod($node->name->toString())
                : $this->registry->lookupMethod($node->class->toString(), $node->name->toString());
        } elseif ($node instanceof MethodCall && $node->var instanceof Variable && 'this' === $node->var->name && $node->name instanceof Identifier) {
            $function = $this->registry->lookupNamedMethod($node->name->toString());
        } elseif ($node instanceof New_ && $node->class instanceof Name) {
            $function = $this->registry->lookupConstructor($node->class->toString());
        } elseif ($node instanceof Attribute) {
            $function = $this->registry->lookupAttribute($node->name->toString());

            return null === $function ? null : [$function, $node->args];
        }

        if (null === $function || !$node instanceof CallLike) {
            return null;
        }

        $args = self::ordinaryArgs($node);

        return null === $args ? null : [$function, $args];
    }

    /**
     * The arguments a call reads its patterns from.
     *
     * @return list<Arg>
     */
    private function patternArguments(Node $node): array
    {
        $match = $this->patternCall($node);
        if (null === $match) {
            return [];
        }

        $read = [];
        foreach ($match[0]->eachArgument() as $function) {
            $arg = $this->findPatternArg($match[1], $function->argumentIndex, $function->parameterNames);
            if (null !== $arg && !\in_array($arg, $read, true)) {
                $read[] = $arg;
            }
        }

        return $read;
    }

    /**
     * The arguments of a call, null for a first-class callable,
     * preg_match(...), or a partial application, preg_match(?, $s): their
     * placeholders are no arguments, and getArgs() asserts on them.
     *
     * @return list<Arg>|null
     */
    private static function ordinaryArgs(CallLike $call): ?array
    {
        $args = [];
        foreach ($call->getRawArgs() as $arg) {
            if (!$arg instanceof Arg) {
                return null;
            }

            $args[] = $arg;
        }

        return $args;
    }

    /**
     * @param array<Arg>                                   $args
     * @param list<PatternOccurrence|PendingPattern<Expr>> $found
     */
    private function extractFromArgs(array $args, PatternFunction $patternFunction, string $file, string $content, array &$found): void
    {
        $read = [];
        foreach ($patternFunction->eachArgument() as $function) {
            $arg = $this->findPatternArg($args, $function->argumentIndex, $function->parameterNames);
            // A named argument stands for every position it may fill: read once.
            if (null === $arg || \in_array($arg, $read, true)) {
                continue;
            }

            $read[] = $arg;

            // Nette's Strings::replace() reads the values of the array when the
            // replacement is a callable.
            if (null !== $function->replacementIndex) {
                $replacement = $this->findPatternArg($args, $function->replacementIndex, PatternFunction::REPLACEMENT_PARAMETER_NAMES);
                if (null !== $replacement && $this->isCallableExpr($replacement->value)) {
                    $function = $function->readingValues();
                }
            }

            // Nette's utf8: true appends "u" to every pattern; anything but
            // true or false leaves its flags unknown.
            if (null !== $function->utf8Argument) {
                $utf8 = self::utf8($args, $function->utf8Argument);
                if (null === $utf8) {
                    continue;
                }

                if ($utf8) {
                    $function = $function->withPatternSuffix('u');
                }
            }

            // filter_var() compiles the option only under FILTER_VALIDATE_REGEXP.
            if (null !== $function->filterArgument) {
                [$index, $name, $constant] = $function->filterArgument;
                $filter = $this->findPatternArg($args, $index, [$name]);
                if (null === $filter || !$filter->value instanceof ConstFetch || $constant !== $filter->value->name->toString()) {
                    continue;
                }
            }

            $value = $this->optionValue($arg->value, $function);
            if (null !== $value) {
                $this->extractPatternFromValue($value, $function, $file, $content, $found);
            }
        }
    }

    /**
     * The expression an argument holds the pattern in: the argument itself,
     * or, for an array literal, the item its option path leads to (the
     * last of a repeated key, as PHP keeps it).
     */
    private function optionValue(Expr $value, PatternFunction $function): ?Expr
    {
        if ([] === $function->optionPath || !$value instanceof Array_) {
            return [] !== $function->optionPath && $function->optionPathRequired ? null : $value;
        }

        foreach ($function->optionPath as $key) {
            if (!$value instanceof Array_) {
                return null;
            }

            $found = null;
            foreach ($value->items as $item) {
                if (null !== $item && $item->unpack) {
                    // A spread may hold the key, replacing what came before.
                    $found = null;
                } elseif (null !== $item && null !== $item->key && $key === $this->extractStringValue($item->key)) {
                    $found = $item->value;
                }
            }

            if (null === $found) {
                return null;
            }

            $value = $found;
        }

        return $value;
    }

    /**
     * Whether an argument is an object or an array whatever its value, as
     * Nette's Strings::replace() tests its replacement: a closure, an arrow
     * function, a first-class callable, an array, a new object, $this, an
     * (object) or (array) cast, a clone, or Closure::fromCallable().
     */
    private function isCallableExpr(Expr $expr): bool
    {
        if ($expr instanceof Closure || $expr instanceof ArrowFunction || $expr instanceof Array_ || $expr instanceof New_
            || $expr instanceof Object_ || $expr instanceof ArrayCast || $expr instanceof Clone_) {
            return true;
        }

        if ($expr instanceof Variable) {
            return 'this' === $expr->name;
        }

        if ($expr instanceof StaticCall && $expr->class instanceof Name && $expr->name instanceof Identifier
            && 'closure' === $expr->class->toLowerString() && 'fromcallable' === $expr->name->toLowerString()) {
            return true;
        }

        // foo(...), Foo::bar(...), $this->bar(...); New_ is a CallLike too.
        return $expr instanceof CallLike && $expr->isFirstClassCallable();
    }

    /**
     * Locate an argument, passed positionally or by name. A spread before
     * its position makes the position unknowable; the name still passes it.
     *
     * @param array<Arg>   $args
     * @param list<string> $names parameter names, compared as PHP does, case included
     */
    private function findPatternArg(array $args, int $argumentIndex, array $names): ?Arg
    {
        $position = 0;

        foreach ($args as $arg) {
            if (null !== $arg->name) {
                continue;
            }

            if ($arg->unpack) {
                break;
            }

            if ($position === $argumentIndex) {
                return $arg;
            }

            $position++;
        }

        return self::namedArg($args, $names);
    }

    /**
     * What the utf8 argument of a nette/utils call is, by position or by
     * name: false when left out; null when it is no literal true or false,
     * or a spread may hold it.
     *
     * @param array<Arg>         $args
     * @param array{int, string} $utf8 its position and its name
     */
    private static function utf8(array $args, array $utf8): ?bool
    {
        $position = 0;
        $value = null;
        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }

            if (null === $arg->name ? $position++ === $utf8[0] : $utf8[1] === $arg->name->toString()) {
                $value = $arg->value;
            }
        }

        if (null === $value) {
            return false;
        }

        $name = $value instanceof ConstFetch ? $value->name->toLowerString() : null;

        return 'true' === $name ? true : ('false' === $name ? false : null);
    }

    /**
     * The argument passed by one of the names, compared as PHP does, case
     * included.
     *
     * @param array<Arg>   $args
     * @param list<string> $names
     */
    private static function namedArg(array $args, array $names): ?Arg
    {
        foreach ($args as $arg) {
            if (null !== $arg->name && \in_array($arg->name->toString(), $names, true)) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * @param list<PatternOccurrence|PendingPattern<Expr>> $found
     */
    private function extractPatternFromValue(Expr $value, PatternFunction $patternFunction, string $file, string $content, array &$found): void
    {
        // A null argument, as any other constant that holds no string, is
        // left out by mayName().

        // preg_replace(['/a/', '/b/'], ...) and preg_replace_callback_array(['/a/' => $fn])
        // hold several patterns in one argument.
        if ($value instanceof Array_) {
            $this->extractPatternsFromArray($value, $patternFunction, $file, $content, $found);

            return;
        }

        $pattern = $this->extractPatternFromExpr($value, $patternFunction, $file, $content);
        if (null !== $pattern) {
            $found[] = $pattern;
        }
    }

    /**
     * @param list<PatternOccurrence|PendingPattern<Expr>> $found
     */
    private function extractPatternsFromArray(Array_ $array, PatternFunction $patternFunction, string $file, string $content, array &$found): void
    {
        $useKeys = $patternFunction->keysArePatterns;
        if ($useKeys && null !== $patternFunction->replacementIndex) {
            // A key that is a string selects the keys, no key or an int key
            // the values.
            $first = $array->items[0] ?? null;
            $useKeys = null !== $first && null !== $first->key && $this->isStringKey($first->key);
        }

        foreach ($array->items as $item) {
            if (null === $item) {
                continue;
            }

            $expr = $useKeys ? $item->key : $item->value;
            if (null === $expr) {
                continue;
            }

            $pattern = $this->extractPatternFromExpr($expr, $patternFunction, $file, $content);
            if (null !== $pattern) {
                $found[] = $pattern;
            }
        }
    }

    /**
     * Whether an array key is a string once PHP stores it: an int literal or
     * a numeric string is not; a key that is no literal is taken for one.
     */
    private function isStringKey(Expr $key): bool
    {
        // An int, a float, true or false, signed or not: PHP stores an int.
        while ($key instanceof UnaryMinus || $key instanceof UnaryPlus) {
            $key = $key->expr;
        }

        if ($key instanceof Int_ || $key instanceof Float_) {
            return false;
        }

        if ($key instanceof ConstFetch && \in_array($key->name->toLowerString(), ['true', 'false'], true)) {
            return false;
        }

        $value = $this->extractStringValue($key);

        return null === $value || PatternFunction::isStringKey($value);
    }

    /**
     * A literal pattern is reported at the call; any other is held until
     * the file is read, for the names it may go through.
     *
     * @return PatternOccurrence|PendingPattern<Expr>|null
     */
    private function extractPatternFromExpr(Expr $expr, PatternFunction $patternFunction, string $file, string $content): PatternOccurrence|PendingPattern|null
    {
        $pattern = $this->extractStringValue($expr);
        if (null === $pattern) {
            return self::mayName($expr) ? new PendingPattern($expr, $patternFunction) : null;
        }

        if ('' === $pattern) {
            return null;
        }

        $offset = $this->normalizeOffset($expr->getStartFilePos());

        return new PatternOccurrence(
            $pattern.$patternFunction->patternSuffix,
            $file,
            $expr->getStartLine(),
            $patternFunction->source(),
            column: null !== $offset ? PendingPattern::column($content, $offset) : null,
            fileOffset: $offset,
        );
    }

    /**
     * Whether a pattern argument that is no literal may still be read
     * through the names of the file: a concatenation, a constant, a local
     * variable, a property of $this.
     */
    private static function mayName(Expr $expr): bool
    {
        if ($expr instanceof ConstFetch) {
            return !\in_array($expr->name->toLowerString(), ['true', 'false', 'null'], true);
        }

        if ($expr instanceof PropertyFetch) {
            // $this->re, or an enum case's backing value, Kind::Re->value.
            if (!$expr->name instanceof Identifier) {
                return false;
            }

            return $expr->var instanceof ClassConstFetch
                ? 'value' === $expr->name->toString()
                : $expr->var instanceof Variable && 'this' === $expr->var->name;
        }

        return $expr instanceof Concat || $expr instanceof ClassConstFetch || ($expr instanceof Variable && \is_string($expr->name) && 'this' !== $expr->name);
    }

    private function extractStringValue(Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        if ($expr instanceof Concat) {
            $left = $this->extractStringValue($expr->left);
            $right = $this->extractStringValue($expr->right);

            if (null === $left || null === $right) {
                return null;
            }

            return $left.$right;
        }

        return null;
    }

    private function normalizeOffset(?int $offset): ?int
    {
        if (null === $offset || $offset < 0) {
            return null;
        }

        return $offset;
    }

    /**
     * @param list<PatternOccurrence>  $occurrences
     * @param array<PatternOccurrence> $items
     *
     * @param-out list<PatternOccurrence> $occurrences
     */
    private function appendOccurrences(array &$occurrences, array $items): void
    {
        foreach ($items as $item) {
            $occurrences[] = $item;
        }
    }
}
