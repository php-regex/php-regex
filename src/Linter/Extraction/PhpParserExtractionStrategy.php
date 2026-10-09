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
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast\Array_ as ArrayCast;
use PhpParser\Node\Expr\Cast\Object_;
use PhpParser\Node\Expr\Clone_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
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
 * and a same-named class of the project's own is not mistaken for one.
 *
 * @internal
 */
final readonly class PhpParserExtractionStrategy implements ExtractorInterface, PatternFunctionAwareInterface
{
    private ?Parser $parser;

    private PatternFunctionRegistry $registry;

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
     * @return array<PatternOccurrence>
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

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);
        } catch (Error|\RangeException $e) {
            // A file PHP cannot parse still holds patterns: the tokenizer
            // reads them, and the run counts the file. A RangeException is
            // the parser meeting a token of a PHP newer than itself. Anything
            // else thrown here is a defect of ours, and is not caught.
            if (TokenBasedExtractionStrategy::holdsNulByte($content)) {
                return [PatternOccurrence::unread($file, \sprintf('Not linted: the PHP parser failed (%s), and the tokenizer does not read a file holding a NUL byte.', $e->getMessage()))];
            }

            return [
                PatternOccurrence::parserFallback($file, $e->getMessage()),
                ...(new TokenBasedExtractionStrategy([], $this->registry))->extract([$file]),
            ];
        }

        return $this->extractFromTokens($ast, $file, $content);
    }

    /**
     * @param array<Node> $tokens
     *
     * @return array<PatternOccurrence>
     */
    private function extractFromTokens(array $tokens, string $file, string $content): array
    {
        $occurrences = [];

        foreach ($tokens as $node) {
            $nodeOccurrences = $this->extractFromNode($node, $file, $content);
            $this->appendOccurrences($occurrences, $nodeOccurrences);
        }

        return $occurrences;
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function extractFromNode(Node $node, string $file, string $content): array
    {
        $occurrences = [];

        if ($node instanceof FuncCall) {
            $this->appendOccurrences($occurrences, $this->extractFromFuncCall($node, $file, $content));
        } elseif ($node instanceof StaticCall) {
            $this->appendOccurrences($occurrences, $this->extractFromStaticCall($node, $file, $content));
        }

        // Recursively check child nodes
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};
            if ($subNode instanceof Node) {
                $this->appendOccurrences($occurrences, $this->extractFromNode($subNode, $file, $content));
            } elseif (\is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->appendOccurrences($occurrences, $this->extractFromNode($item, $file, $content));
                    }
                }
            }
        }

        return $occurrences;
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function extractFromFuncCall(FuncCall $funcCall, string $file, string $content): array
    {
        $args = self::ordinaryArgs($funcCall);
        if (!$funcCall->name instanceof Name || null === $args) {
            return [];
        }

        // An unqualified name in a namespace: PHP calls the namespace's
        // function first, the global one when there is none.
        $namespaced = $funcCall->name->getAttribute('namespacedName');
        $patternFunction = $this->registry->lookupCall($namespaced instanceof Name ? $namespaced->toString() : null, $funcCall->name->toString());
        if (null === $patternFunction) {
            return [];
        }

        return $this->extractFromArgs($args, $patternFunction, $file, $content);
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function extractFromStaticCall(StaticCall $staticCall, string $file, string $content): array
    {
        $args = self::ordinaryArgs($staticCall);
        if (!$staticCall->class instanceof Name || !$staticCall->name instanceof Identifier || null === $args) {
            return [];
        }

        $patternFunction = $this->registry->lookupMethod($staticCall->class->toString(), $staticCall->name->toString());
        if (null === $patternFunction) {
            return [];
        }

        return $this->extractFromArgs($args, $patternFunction, $file, $content);
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
     * @param array<Arg> $args
     *
     * @return array<PatternOccurrence>
     */
    private function extractFromArgs(array $args, PatternFunction $patternFunction, string $file, string $content): array
    {
        $occurrences = [];
        $read = [];
        foreach ($patternFunction->eachArgument() as $function) {
            $arg = $this->findPatternArg($args, $function->argumentIndex);
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

            array_push($occurrences, ...$this->extractPatternFromArg($arg, $function, $file, $content));
        }

        return $occurrences;
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
     * Locate the pattern argument, whether it was passed positionally or by name.
     *
     * @param array<Arg>   $args
     * @param list<string> $names lowercase parameter names
     */
    private function findPatternArg(array $args, int $argumentIndex, array $names = PatternFunction::PATTERN_PARAMETER_NAMES): ?Arg
    {
        $position = 0;

        foreach ($args as $arg) {
            if (null !== $arg->name) {
                continue;
            }

            // A spread makes every later position unknowable.
            if ($arg->unpack) {
                return null;
            }

            if ($position === $argumentIndex) {
                return $arg;
            }

            $position++;
        }

        foreach ($args as $arg) {
            if (null === $arg->name) {
                continue;
            }

            if (\in_array(strtolower($arg->name->toString()), $names, true)) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function extractPatternFromArg(Arg $arg, PatternFunction $patternFunction, string $file, string $content): array
    {
        $value = $arg->value;

        if ($value instanceof ConstFetch && 'null' === $value->name->toString()) {
            return [];
        }

        // preg_replace(['/a/', '/b/'], ...) and preg_replace_callback_array(['/a/' => $fn])
        // hold several patterns in one argument.
        if ($value instanceof Array_) {
            return $this->extractPatternsFromArray($value, $patternFunction, $file, $content);
        }

        $occurrence = $this->extractPatternFromExpr($value, $patternFunction, $file, $content);

        return null !== $occurrence ? [$occurrence] : [];
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function extractPatternsFromArray(Array_ $array, PatternFunction $patternFunction, string $file, string $content): array
    {
        $occurrences = [];
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

            $occurrence = $this->extractPatternFromExpr($expr, $patternFunction, $file, $content);
            if (null !== $occurrence) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
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

    private function extractPatternFromExpr(Expr $expr, PatternFunction $patternFunction, string $file, string $content): ?PatternOccurrence
    {
        $pattern = $this->extractStringValue($expr);
        if (null === $pattern || '' === $pattern) {
            return null;
        }

        $offset = $this->normalizeOffset($expr->getStartFilePos());

        return new PatternOccurrence(
            $pattern,
            $file,
            $expr->getStartLine(),
            $patternFunction->label.'()',
            column: null !== $offset ? $this->columnFromOffset($content, $offset) : null,
            fileOffset: $offset,
        );
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

    private function columnFromOffset(string $content, ?int $offset): ?int
    {
        if (null === $offset || $offset < 0) {
            return null;
        }

        $prefix = substr($content, 0, $offset);
        $lastNewline = strrpos($prefix, "\n");
        if (false === $lastNewline) {
            return $offset + 1;
        }

        return $offset - $lastNewline;
    }

    /**
     * @param array<PatternOccurrence> $occurrences
     * @param array<PatternOccurrence> $items
     */
    private function appendOccurrences(array &$occurrences, array $items): void
    {
        foreach ($items as $item) {
            $occurrences[] = $item;
        }
    }
}
