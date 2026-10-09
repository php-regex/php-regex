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

use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Token-based extraction strategy mirroring PHPStan's preg_* handling.
 *
 * This relies on a small token state machine to track argument positions
 * and only extracts patterns from constant string expressions.
 *
 * @internal
 */
final readonly class TokenBasedExtractionStrategy implements ExtractorInterface, PatternFunctionAwareInterface
{
    private const IGNORABLE_TOKENS = [
        \T_WHITESPACE => true,
        \T_COMMENT => true,
        \T_DOC_COMMENT => true,
    ];

    /**
     * Matches an identifier, so a reserved word used as a method name — the
     * "match" of Preg::match() is tokenized as T_MATCH, not T_STRING — is
     * still read as one.
     */
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/';

    private PatternFunctionRegistry $registry;

    /**
     * @param array<int, string> $customFunctions Additional functions/static methods to check (e.g., 'MyClass::customRegexCheck')
     */
    public function __construct(array $customFunctions = [], ?PatternFunctionRegistry $registry = null)
    {
        $this->registry = ($registry ?? PatternFunctionRegistry::defaults())->withCustomFunctions($customFunctions);
    }

    public function withPatternFunctions(array $specs, array $plain = []): static
    {
        return new self([], $this->registry->withDeclaredFunctions($specs, $plain)->withDeclarationsRead());
    }

    public function extract(array $files): array
    {
        // Used on its own, the functions the files mark with #[RegexPattern]
        // join the registry for this run.
        $specs = $this->registry->declarationsRead() ? [] : PatternAttributeScanner::specs($files);
        $strategy = [] === $specs ? $this : new self([], $this->registry->withDeclaredFunctions($specs));

        return $strategy->extractFiles($files);
    }

    /**
     * Whether the tokenizer passes the content over as binary.
     */
    public static function holdsNulByte(string $content): bool
    {
        return str_contains($content, "\x00");
    }

    /**
     * @param array<string> $files
     *
     * @return array<PatternOccurrence>
     */
    private function extractFiles(array $files): array
    {
        $occurrences = [];
        foreach ($files as $file) {
            $this->appendOccurrences($occurrences, $this->extractFromFile($file));
        }

        return $occurrences;
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function extractFromFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $content = is_readable($file) ? @file_get_contents($file) : false;
        if (false === $content) {
            return [PatternOccurrence::unread($file, 'Not linted: the file could not be read.')];
        }

        if ('' === $content || $this->shouldSkipContent($content)) {
            return [];
        }

        if (!MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR)) {
            return [PatternOccurrence::unread($file, MemoryBudget::refusal($content, MemoryBudget::TOKENIZE_FACTOR))];
        }

        $content = $this->readableContent($content);
        if (null === $content) {
            return [];
        }

        $tokens = token_get_all($content);
        $tokenOffsets = $this->buildTokenOffsets($tokens);
        $closers = $this->matchBrackets($tokens);
        $occurrences = [];
        $totalTokens = \count($tokens);
        $context = new NameResolutionContext();

        for ($i = 0; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $i = $this->readNamespaceDeclaration($tokens, $i, $totalTokens, $context);

                continue;
            }

            if (\T_USE === $token[0]) {
                $i = $this->readUseStatement($tokens, $i, $totalTokens, $context);

                continue;
            }

            $match = $this->matchFunctionCall($tokens, $i, $totalTokens, $context);
            if (null === $match) {
                continue;
            }

            [$patternFunction, $openParenIndex] = $match;

            foreach ($patternFunction->eachArgument() as $function) {
                $this->appendOccurrences($occurrences, $this->extractFromCall(
                    $tokens,
                    $openParenIndex + 1,
                    $totalTokens,
                    $function,
                    $file,
                    $tokenOffsets,
                    $content,
                    $closers,
                    $context,
                ));
            }
        }

        return $occurrences;
    }

    /**
     * Record the namespace a "namespace X;" or "namespace X { ... }" opens.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return int index to resume scanning from
     */
    private function readNamespaceDeclaration(array $tokens, int $index, int $totalTokens, NameResolutionContext $context): int
    {
        $nameIndex = $this->nextSignificantTokenIndex($tokens, $index + 1, $totalTokens);
        if (null === $nameIndex) {
            return $index;
        }

        $name = $this->readNameToken($tokens[$nameIndex]);
        if (null === $name) {
            // "namespace { ... }" declares the global namespace.
            if ('{' === $tokens[$nameIndex]) {
                $context->enterNamespace('');

                return $nameIndex;
            }

            return $index;
        }

        $context->enterNamespace($name);

        return $nameIndex;
    }

    /**
     * Record the aliases an import statement brings into scope.
     *
     * Closure "use ($var)" and trait imports are skipped; the latter cannot be
     * told apart from a class import by tokens alone, and treating one as an
     * alias is harmless.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return int index to resume scanning from
     */
    private function readUseStatement(array $tokens, int $index, int $totalTokens, NameResolutionContext $context): int
    {
        $cursor = $this->nextSignificantTokenIndex($tokens, $index + 1, $totalTokens);
        if (null === $cursor) {
            return $index;
        }

        // Closure capture: use ($foo, $bar)
        if ('(' === $tokens[$cursor]) {
            return $index;
        }

        $isFunction = false;
        $token = $tokens[$cursor];
        if (\is_array($token)) {
            if (\T_CONST === $token[0]) {
                return $this->skipToStatementEnd($tokens, $cursor, $totalTokens);
            }

            if (\T_FUNCTION === $token[0]) {
                $isFunction = true;
                $next = $this->nextSignificantTokenIndex($tokens, $cursor + 1, $totalTokens);
                if (null === $next) {
                    return $index;
                }

                $cursor = $next;
            }
        }

        $prefix = '';
        $name = '';
        $alias = null;
        $expectAlias = false;

        for (; $cursor < $totalTokens; $cursor++) {
            $token = $tokens[$cursor];

            if ($this->isIgnorableToken($token)) {
                continue;
            }

            if (';' === $token) {
                $this->commitImport($context, $prefix, $name, $alias, $isFunction);

                return $cursor;
            }

            if ('{' === $token) {
                // Group import: the name read so far is the shared prefix.
                $prefix = '' === $name ? $prefix : rtrim($name, '\\').'\\';
                $name = '';
                $alias = null;
                $expectAlias = false;

                continue;
            }

            if ('}' === $token) {
                $this->commitImport($context, $prefix, $name, $alias, $isFunction);
                $prefix = '';
                $name = '';
                $alias = null;
                $expectAlias = false;

                continue;
            }

            if (',' === $token) {
                $this->commitImport($context, $prefix, $name, $alias, $isFunction);
                $name = '';
                $alias = null;
                $expectAlias = false;

                continue;
            }

            if (\is_array($token) && \T_DOUBLE_COLON === $token[0]) {
                // A trait conflict block ("use A, B { B::x insteadof A; }")
                // imports nothing.
                return $this->skipToStatementEnd($tokens, $cursor, $totalTokens);
            }

            if (\is_array($token) && \T_AS === $token[0]) {
                $expectAlias = true;

                continue;
            }

            if (\is_array($token) && \T_NS_SEPARATOR === $token[0]) {
                continue;
            }

            $read = $this->readNameToken($token);
            if (null === $read) {
                continue;
            }

            if ($expectAlias) {
                $alias = $read;

                continue;
            }

            $name = $read;
        }

        return $cursor - 1;
    }

    private function commitImport(NameResolutionContext $context, string $prefix, string $name, ?string $alias, bool $isFunction): void
    {
        if ('' === $name) {
            return;
        }

        $target = $prefix.$name;
        if (null === $alias) {
            $separator = strrpos($name, '\\');
            $alias = false === $separator ? $name : substr($name, $separator + 1);
        }

        if ('' === $alias) {
            return;
        }

        if ($isFunction) {
            $context->importFunction($alias, $target);

            return;
        }

        $context->importClass($alias, $target);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function skipToStatementEnd(array $tokens, int $index, int $totalTokens): int
    {
        for ($i = $index; $i < $totalTokens; $i++) {
            if (';' === $tokens[$i]) {
                return $i;
            }
        }

        return $totalTokens - 1;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{PatternFunction, int}|null
     */
    private function matchFunctionCall(array $tokens, int $index, int $totalTokens, NameResolutionContext $context): ?array
    {
        $name = $this->readNameToken($tokens[$index]);
        if (null === $name) {
            return null;
        }

        $nextIndex = $this->nextSignificantTokenIndex($tokens, $index + 1, $totalTokens);
        if (null !== $nextIndex && $this->isDoubleColonToken($tokens[$nextIndex])) {
            return $this->matchStaticMethodCall($tokens, $index, $nextIndex, $totalTokens, $context);
        }

        // From here on we treat this as a plain function call and decide
        // whether it is a registered function.
        $prevIndex = $this->previousSignificantTokenIndex($tokens, $index - 1);
        if (null !== $prevIndex) {
            $prevToken = $tokens[$prevIndex];
            if ($this->isDefinitionToken($prevToken) || $this->isObjectOrStaticOperator($prevToken)) {
                return null;
            }
        }

        if (null === $nextIndex || '(' !== $tokens[$nextIndex]) {
            return null;
        }

        // PHP calls the current namespace's function first, the global one
        // when there is none.
        $namespaced = $context->namespacedFunction($name);
        $patternFunction = $this->registry->lookupCall($namespaced, $context->resolveFunction($name));
        if (null === $patternFunction) {
            return null;
        }

        return [$patternFunction, $nextIndex];
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{PatternFunction, int}|null
     */
    private function matchStaticMethodCall(array $tokens, int $classIndex, int $doubleColonIndex, int $totalTokens, NameResolutionContext $context): ?array
    {
        $methodIndex = $this->nextSignificantTokenIndex($tokens, $doubleColonIndex + 1, $totalTokens);
        if (null === $methodIndex) {
            return null;
        }

        $methodName = $this->readIdentifierToken($tokens[$methodIndex]);
        if (null === $methodName) {
            return null;
        }

        $openParenIndex = $this->nextSignificantTokenIndex($tokens, $methodIndex + 1, $totalTokens);
        if (null === $openParenIndex || '(' !== $tokens[$openParenIndex]) {
            return null;
        }

        $className = $this->readNameToken($tokens[$classIndex]);
        if (null === $className) {
            return null;
        }

        $patternFunction = $this->registry->lookupMethod($context->resolveClass($className), $methodName);
        if (null === $patternFunction) {
            return null;
        }

        return [$patternFunction, $openParenIndex];
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenOffsets
     * @param array<int, int>|null                       $closers      the closing index of each bracket, from matchBrackets()
     *
     * @return array<PatternOccurrence>
     */
    private function extractFromCall(
        array $tokens,
        int $startIndex,
        int $totalTokens,
        PatternFunction $patternFunction,
        string $file,
        array $tokenOffsets,
        string $content,
        ?array $closers = null,
        ?NameResolutionContext $context = null,
    ): array {
        $closers ??= $this->matchBrackets($tokens);
        $argument = $this->findArgument($tokens, $startIndex, $totalTokens, $patternFunction->argumentIndex, PatternFunction::PATTERN_PARAMETER_NAMES, $closers);
        if (null === $argument) {
            return [];
        }

        // Nette's Strings::replace() reads the values of the array when the
        // replacement is an object or an array: a callable.
        if (null !== $patternFunction->replacementIndex) {
            $replacement = $this->findArgument($tokens, $startIndex, $totalTokens, $patternFunction->replacementIndex, PatternFunction::REPLACEMENT_PARAMETER_NAMES, $closers);
            if (null !== $replacement && $this->isObjectOrArray($tokens, $replacement[0], $replacement[1], $closers, $context ?? new NameResolutionContext())) {
                $patternFunction = $patternFunction->readingValues();
            }
        }

        [$argumentTokens, $argumentIndexes] = $this->sliceTokens($tokens, $argument[0], $argument[1]);

        return $this->extractFromArgumentTokens($argumentTokens, $argumentIndexes, $tokenOffsets, $content, $file, $patternFunction);
    }

    /**
     * The index of the token closing each bracket: ( [ { and the "{$", "${"
     * and "#[" a plain "}" or "]" closes. An opener left open has none.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<int, int>
     */
    private function matchBrackets(array $tokens): array
    {
        $closers = [];
        $open = [];
        foreach ($tokens as $index => $token) {
            if ($this->opensNesting($token)) {
                $open[] = $index;
            } elseif ((')' === $token || ']' === $token || '}' === $token) && [] !== $open) {
                $closers[array_pop($open)] = $index;
            }
        }

        return $closers;
    }

    /**
     * Locate an argument of a call, passed positionally or by name.
     *
     * A positional argument at the position wins; otherwise the first
     * argument passed under one of the names. A spread before that position
     * makes it unknowable. Nothing is copied, and a bracket is stepped over
     * whole, so that a call nested in another is not read again for each
     * call around it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param list<string>                               $names   lowercase parameter names
     * @param array<int, int>                            $closers the closing index of each bracket
     *
     * @return array{0: int, 1: int}|null where the argument's value starts and where it ends, past its last token
     */
    private function findArgument(array $tokens, int $startIndex, int $totalTokens, int $targetArgIndex, array $names, array $closers): ?array
    {
        $position = 0;
        $named = null;
        $argumentStart = $startIndex;
        $i = $startIndex;

        while (true) {
            // Running out of tokens closes the call: the source is cut short.
            $token = $i < $totalTokens ? $tokens[$i] : ')';
            $closesCall = ')' === $token;

            if ($closesCall || ',' === $token) {
                // Null for the empty slot a trailing comma leaves.
                $argument = $this->readArgument($tokens, $argumentStart, $i);
                if (null !== $argument && null === $argument['name']) {
                    if ($argument['spread']) {
                        return null;
                    }

                    if ($position === $targetArgIndex) {
                        return [$argument['valueStart'], $i];
                    }

                    $position++;
                } elseif (null !== $argument && null === $named && \in_array(strtolower((string) $argument['name']), $names, true)) {
                    $named = [$argument['valueStart'], $i];
                }

                if ($closesCall) {
                    return $named;
                }

                $argumentStart = $i + 1;
            } elseif ($this->opensNesting($token)) {
                // Step over the bracket; one left open runs to the end.
                $i = $closers[$i] ?? $totalTokens;
            }

            $i++;
        }
    }

    /**
     * What an argument is, from its first tokens: a spread, an argument
     * passed by name, or a positional one.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{name: string|null, spread: bool, valueStart: int}|null null for an empty slot
     */
    private function readArgument(array $tokens, int $start, int $end): ?array
    {
        $first = $this->nextSignificantTokenIndex($tokens, $start, $end);
        if (null === $first) {
            return null;
        }

        $token = $tokens[$first];
        if (\is_array($token) && \T_ELLIPSIS === $token[0]) {
            return ['name' => null, 'spread' => true, 'valueStart' => $start];
        }

        // "pattern: '/re/'": an identifier, any reserved word included,
        // followed by a single colon.
        $name = $this->readIdentifierToken($token);
        $colon = null === $name ? null : $this->nextSignificantTokenIndex($tokens, $first + 1, $end);
        if (null !== $colon && ':' === $tokens[$colon]) {
            return ['name' => $name, 'spread' => false, 'valueStart' => $colon + 1];
        }

        return ['name' => null, 'spread' => false, 'valueStart' => $start];
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{0: list<array{int, string, int}|string>, 1: list<int>}
     */
    private function sliceTokens(array $tokens, int $start, int $end): array
    {
        // A name with no value after it, "pattern:)", leaves nothing.
        return [array_values(\array_slice($tokens, $start, $end - $start)), $end > $start ? range($start, $end - 1) : []];
    }

    /**
     * Whether the argument in [$start, $end) is an object or an array
     * whatever its value, as Nette's Strings::replace() tests its
     * replacement: a closure, an arrow function, a first-class callable, an
     * array, a new object, $this, an (object) or (array) cast, a clone, or
     * Closure::fromCallable(). Each counts only when it is the whole
     * argument: $cb ?? strtoupper(...) is not one.
     *
     * Read in place with the bracket map, so a closure holding the next call
     * is not read again.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers the closing index of each bracket
     */
    private function isObjectOrArray(array $tokens, int $start, int $end, array $closers, NameResolutionContext $context): bool
    {
        // An empty value leaves $first past $last: nothing below matches.
        $first = $this->nextSignificantTokenIndex($tokens, $start, $end) ?? $end;
        $last = $this->previousSignificantTokenIndex($tokens, $end - 1) ?? $start;
        while ('(' === ($tokens[$first] ?? null) && ($closers[$first] ?? null) === $last) {
            $first = $this->nextSignificantTokenIndex($tokens, $first + 1, $last) ?? $last;
            $last = $this->previousSignificantTokenIndex($tokens, $last - 1) ?? $first;
        }

        $token = $tokens[$first] ?? ';';
        $next = $this->nextSignificantTokenIndex($tokens, $first + 1, $last + 1) ?? $last + 1;

        if ('[' === $token) {
            return ($closers[$first] ?? null) === $last;
        }

        if (!\is_array($token)) {
            return false;
        }

        $whole = match ($token[0]) {
            \T_ARRAY => '(' === ($tokens[$next] ?? null) && ($closers[$next] ?? null) === $last,
            \T_VARIABLE => '$this' === $token[1] && $first === $last,
            \T_OBJECT_CAST, \T_ARRAY_CAST, \T_CLONE => $this->isMemberChain($tokens, $next, $last, $closers),
            \T_NEW => $this->constructionEnd($tokens, $next, $last, $closers) === $last,
            \T_ATTRIBUTE, \T_STATIC, \T_FUNCTION, \T_FN => $this->closureEnd($tokens, $first, $last, $closers) === $last,
            default => $this->isClosureFromCallable($tokens, $first, $next, $last, $closers, $context),
        };

        return $whole || $this->isFirstClassCallable($tokens, $first, $last, $closers);
    }

    /**
     * Where a closure or an arrow function starting at $index ends: an arrow
     * function runs to the end of the argument, a closure to the brace
     * closing its body. Null when the tokens start no closure.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private function closureEnd(array $tokens, int $index, int $last, array $closers): ?int
    {
        // Attributes, then static; past $last stands the comma or the
        // parenthesis ending the argument.
        while (\is_array($tokens[$index] ?? null) && \in_array($tokens[$index][0], [\T_ATTRIBUTE, \T_STATIC], true)) {
            $after = \T_ATTRIBUTE === $tokens[$index][0] ? ($closers[$index] ?? $last) : $index;
            $index = $this->nextSignificantTokenIndex($tokens, $after + 1, $last + 1) ?? $last + 1;
        }

        $token = $tokens[$index] ?? ';';
        if (\is_array($token) && \T_FN === $token[0]) {
            return $last;
        }

        if (!\is_array($token) || \T_FUNCTION !== $token[0]) {
            return null;
        }

        return $this->bodyEnd($tokens, $index + 1, $last, $closers);
    }

    /**
     * Where the object a new expression builds ends, from the token after
     * "new": the brace closing an anonymous class, the parenthesis closing
     * the arguments, or the end of the argument when there are none.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private function constructionEnd(array $tokens, int $index, int $last, array $closers): ?int
    {
        $token = $tokens[$index] ?? null;
        if (\is_array($token) && \T_CLASS === $token[0]) {
            return $this->bodyEnd($tokens, $index + 1, $last, $closers);
        }

        for ($i = $index; $i <= $last; $i++) {
            if ('(' === $tokens[$i]) {
                return $closers[$i] ?? null;
            }

            // new $classes['a'] names its class with a bracket.
            if ($this->opensNesting($tokens[$i])) {
                $i = $closers[$i] ?? $last;
            }
        }

        return $last;
    }

    /**
     * The brace closing the first body opened from $index, stepping over
     * the parameter list and use() before it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private function bodyEnd(array $tokens, int $index, int $last, array $closers): ?int
    {
        // With no body, $i passes $last, where no bracket opens.
        $i = $index;
        while ($i <= $last && '{' !== $tokens[$i]) {
            $i = ($this->opensNesting($tokens[$i]) ? ($closers[$i] ?? $last) : $i) + 1;
        }

        return $closers[$i] ?? null;
    }

    /**
     * Whether $tokens[$from..$to] is a variable, a name or a member chain on
     * them, with nothing else: $a, $this->b['c'], Foo::$d, strtoupper(...).
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private function isMemberChain(array $tokens, int $from, int $to, array $closers): bool
    {
        // A keyword is a member name after -> or :: only: "$a or $b" is no chain.
        $afterOperator = false;
        for ($i = $from; $i <= $to; $i++) {
            $token = $tokens[$i];
            if ($this->isIgnorableToken($token) || '$' === $token) {
                continue;
            }

            if ($this->opensNesting($token)) {
                $i = $closers[$i] ?? $to + 1;
                $afterOperator = false;

                continue;
            }

            if ($afterOperator ? null === $this->readIdentifierToken($token) && !$this->isVariableToken($token) : !$this->startsMemberChain($token)) {
                return false;
            }

            $afterOperator = $this->isObjectOrStaticOperator($token);
        }

        return $from <= $to && $i === $to + 1;
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function startsMemberChain(array|string $token): bool
    {
        return $this->isVariableToken($token) || $this->isObjectOrStaticOperator($token)
            || null !== $this->readNameToken($token) || (\is_array($token) && \T_STATIC === $token[0]);
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isVariableToken(array|string $token): bool
    {
        return \is_array($token) && \T_VARIABLE === $token[0];
    }

    /**
     * foo(...), Foo::bar(...), $this->bar(...): a member chain whose last
     * call takes "..." alone.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private function isFirstClassCallable(array $tokens, int $first, int $last, array $closers): bool
    {
        $ellipsis = $this->previousSignificantTokenIndex($tokens, $last - 1) ?? $last;
        $open = $this->previousSignificantTokenIndex($tokens, $ellipsis - 1) ?? $ellipsis;
        $token = $tokens[$ellipsis];

        return \is_array($token) && \T_ELLIPSIS === $token[0] && ($closers[$open] ?? null) === $last
            && $open > $first && $this->isMemberChain($tokens, $first, $last, $closers);
    }

    /**
     * Closure::fromCallable(...), with the class resolved as PHP does.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private function isClosureFromCallable(array $tokens, int $first, int $next, int $last, array $closers, NameResolutionContext $context): bool
    {
        $class = $this->readNameToken($tokens[$first]);
        if (null === $class || 'closure' !== strtolower($context->resolveClass($class)) || !$this->isDoubleColonToken($tokens[$next] ?? '')) {
            return false;
        }

        $method = $this->nextSignificantTokenIndex($tokens, $next + 1, $last + 1) ?? $last;
        $open = $this->nextSignificantTokenIndex($tokens, $method + 1, $last + 1) ?? $last;

        return 'fromcallable' === strtolower($this->readIdentifierToken($tokens[$method]) ?? '')
            && '(' === $tokens[$open] && ($closers[$open] ?? null) === $last;
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function opensNesting(array|string $token): bool
    {
        if (!\is_array($token)) {
            return '(' === $token || '[' === $token || '{' === $token;
        }

        // "{$x}" and "${x}" inside a string, and "#[" of an attribute, are
        // closed by a plain "}" or "]".
        return \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES, \T_ATTRIBUTE], true);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array<PatternOccurrence>
     */
    private function extractFromArgumentTokens(
        array $tokens,
        array $tokenIndexes,
        array $tokenOffsets,
        string $content,
        string $file,
        PatternFunction $patternFunction
    ): array {
        [$stripped, $strippedIndexes] = $this->stripOuterParentheses($tokens, $tokenIndexes);

        // preg_replace(['/a/', '/b/'], ...) and preg_replace_callback_array(['/a/' => $fn])
        // hold several patterns in one argument.
        if (null !== $this->findArrayStartIndex($stripped)) {
            return $this->extractFromArrayLiteral($stripped, $strippedIndexes, $tokenOffsets, $content, $file, $patternFunction);
        }

        // Outside an array literal a keys-function still gets one pattern:
        // Nette's Strings::replace($s, '/re/', 'x') takes a plain string.
        $patternInfo = $this->parseRegexExpression($stripped, $strippedIndexes, $tokenOffsets, $content)
            ?? $this->parseConstantStringExpression($stripped, $strippedIndexes, $tokenOffsets, $content);

        if (null === $patternInfo || '' === $patternInfo['pattern']) {
            return [];
        }

        return [$this->createOccurrence($patternInfo, $file, $patternFunction)];
    }

    /**
     * Read every pattern out of an array literal, taking either its keys or
     * its values depending on the call being analysed.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array<PatternOccurrence>
     */
    private function extractFromArrayLiteral(
        array $tokens,
        array $tokenIndexes,
        array $tokenOffsets,
        string $content,
        string $file,
        PatternFunction $patternFunction
    ): array {
        $startIndex = $this->findArrayStartIndex($tokens);
        if (null === $startIndex) {
            return [];
        }

        // Null until the first item says: a key that is a string selects the
        // keys, no key or an int key the values.
        $useKeys = $patternFunction->keysArePatterns ? (null === $patternFunction->replacementIndex ? true : null) : false;
        $occurrences = [];
        $totalTokens = \count($tokens);
        $stack = [$this->closingTokenFor($tokens[$startIndex])];
        $collecting = true;
        $segmentTokens = [];
        /** @var array<int, int> $segmentTokenIndexes */
        $segmentTokenIndexes = [];

        for ($i = $startIndex + 1; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            $tokenIndex = $tokenIndexes[$i] ?? -1;

            if ($this->isIgnorableToken($token)) {
                if ($collecting) {
                    $segmentTokens[] = $token;
                    $segmentTokenIndexes[] = $tokenIndex;
                }

                continue;
            }

            if (\is_array($token) && \T_ARRAY === $token[0]) {
                $nextIndex = $this->nextSignificantTokenIndex($tokens, $i + 1, $totalTokens);
                if (null !== $nextIndex && '(' === $tokens[$nextIndex]) {
                    $stack[] = ')';
                    if ($collecting) {
                        $segmentTokens[] = '(';
                        $segmentTokenIndexes[] = $tokenIndex;
                    }
                    $i = $nextIndex;

                    continue;
                }
            }

            if ($this->opensNesting($token)) {
                $stack[] = $this->closingTokenFor($token);
                if ($collecting) {
                    $segmentTokens[] = $token;
                    $segmentTokenIndexes[] = $tokenIndex;
                }

                continue;
            }

            if ($this->isClosingToken($token, end($stack))) {
                array_pop($stack);
                if (empty($stack)) {
                    if (!$useKeys) {
                        $this->appendOccurrenceFromSegment($occurrences, $segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content, $file, $patternFunction);
                    }

                    break;
                }

                if ($collecting) {
                    $segmentTokens[] = $token;
                    $segmentTokenIndexes[] = $tokenIndex;
                }

                continue;
            }

            $atTopLevel = 1 === \count($stack);

            if ($atTopLevel && ',' === $token) {
                if (!$useKeys) {
                    $this->appendOccurrenceFromSegment($occurrences, $segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content, $file, $patternFunction);
                }

                $useKeys ??= false;
                $collecting = true;
                $segmentTokens = [];
                $segmentTokenIndexes = [];

                continue;
            }

            if ($atTopLevel && $this->isDoubleArrowToken($token)) {
                $useKeys ??= $this->isStringKey($segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content);
                if ($useKeys) {
                    $this->appendOccurrenceFromSegment($occurrences, $segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content, $file, $patternFunction);
                    $collecting = false;
                }

                // Whatever was collected was the key; the value follows.
                $segmentTokens = [];
                $segmentTokenIndexes = [];

                continue;
            }

            if ($collecting) {
                $segmentTokens[] = $token;
                $segmentTokenIndexes[] = $tokenIndex;
            }
        }

        return $occurrences;
    }

    /**
     * Whether an array key is a string once PHP stores it: an int literal or
     * a numeric string is not; a key that is no literal is taken for one.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     */
    private function isStringKey(array $tokens, array $tokenIndexes, array $tokenOffsets, string $content): bool
    {
        [$tokens, $tokenIndexes] = $this->stripOuterParentheses($tokens, $tokenIndexes);
        $significant = array_values(array_filter($tokens, fn (array|string $token): bool => !$this->isIgnorableToken($token)));

        // An int, a float, true or false, signed or not: PHP stores an int.
        $sign = 0;
        while ('+' === ($significant[$sign] ?? null) || '-' === ($significant[$sign] ?? null)) {
            $sign++;
        }

        $scalar = $significant[$sign] ?? null;
        if (\is_array($scalar) && $sign + 1 === \count($significant)) {
            $isNumber = \T_LNUMBER === $scalar[0] || \T_DNUMBER === $scalar[0];
            $isBool = 0 === $sign && null !== $this->readNameToken($scalar) && \in_array(strtolower(ltrim($scalar[1], '\\')), ['true', 'false'], true);
            if ($isNumber || $isBool) {
                return false;
            }
        }

        $key = $this->parseConstantStringExpression($tokens, $tokenIndexes, $tokenOffsets, $content);

        return null === $key || PatternFunction::isStringKey($key['pattern']);
    }

    /**
     * @param array<PatternOccurrence>                   $occurrences
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     */
    private function appendOccurrenceFromSegment(
        array &$occurrences,
        array $tokens,
        array $tokenIndexes,
        array $tokenOffsets,
        string $content,
        string $file,
        PatternFunction $patternFunction
    ): void {
        [$tokens, $tokenIndexes] = $this->stripOuterParentheses($tokens, $tokenIndexes);
        $patternInfo = $this->parseConstantStringExpression($tokens, $tokenIndexes, $tokenOffsets, $content);
        if (null === $patternInfo || '' === $patternInfo['pattern']) {
            return;
        }

        $occurrences[] = $this->createOccurrence($patternInfo, $file, $patternFunction);
    }

    /**
     * @param array{pattern: string, line: int, offset?: int|null, column?: int|null} $patternInfo
     */
    private function createOccurrence(array $patternInfo, string $file, PatternFunction $patternFunction): PatternOccurrence
    {
        return new PatternOccurrence(
            $patternInfo['pattern'],
            $file,
            $patternInfo['line'],
            $patternFunction->label.'()',
            column: $patternInfo['column'] ?? null,
            fileOffset: $patternInfo['offset'] ?? null,
        );
    }

    /**
     * Parse a regex expression, handling patterns with flags.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array{pattern: string, line: int, offset?: int|null, column?: int|null}|null
     */
    private function parseRegexExpression(array $tokens, array $tokenIndexes, array $tokenOffsets, string $content): ?array
    {
        $result = $this->parseConstantStringExpression($tokens, $tokenIndexes, $tokenOffsets, $content);
        if (null === $result) {
            return null;
        }

        $pattern = $result['pattern'];

        // Check if this looks like a regex with flags (e.g., "/pattern/m" or "{pattern}u")
        // Need to handle escaped delimiters in the string
        if (LibraryPcre::match('/^([\'"{}\/#~%])(.*?)([\'"{}\/#~%])([A-Za-z]*)\z/', $pattern, $matches)) {
            $delimiter = $matches[1];
            $regexBody = $matches[2];
            $flags = $matches[4];

            // The pattern body returned from parseConstantStringExpression()
            // has already been decoded from the PHP string literal. Avoid
            // running stripslashes() again here, which would incorrectly
            // drop significant escapes like \\d, \\w, or \\x7f.
            $closingDelimiter = '{' === $delimiter ? '}' : $delimiter;

            // A pattern whose closing delimiter does not match its opening
            // delimiter (e.g. "/foo#") is broken at runtime; do not "repair"
            // it here — fall back so the raw pattern is validated as-is and
            // the delimiter error surfaces in the lint report.
            if ($matches[3] !== $closingDelimiter) {
                return null;
            }

            // Reconstruct the pattern with flags preserved, using the proper
            // closing delimiter for bracket-style delimiters.
            $fullPattern = $delimiter.$regexBody.$closingDelimiter.$flags;

            return [
                'pattern' => $fullPattern,
                'line' => $result['line'],
                'offset' => $result['offset'] ?? null,
                'column' => $result['column'] ?? null,
            ];
        }

        // Not a regex with flags, let the caller fall back to plain string parsing.
        return null;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array{pattern: string, line: int, offset?: int|null, column?: int|null}|null
     */
    private function parseConstantStringExpression(array $tokens, array $tokenIndexes, array $tokenOffsets, string $content): ?array
    {
        $parts = [];
        $firstLine = null;
        $firstTokenOffset = null;
        $firstTokenColumn = null;
        $expectString = true;
        // The opening token and raw body of the heredoc being read, if any.
        $heredoc = null;

        foreach ($tokens as $index => $token) {
            if (null !== $heredoc) {
                if (\is_array($token) && \T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                    $heredoc[1] .= $token[1];

                    continue;
                }

                // Anything else between the markers is interpolation.
                if (!\is_array($token) || \T_END_HEREDOC !== $token[0]) {
                    return null;
                }

                $decoded = PhpStringLiteral::decodeHeredoc($heredoc[0], $heredoc[1], $token[1]);
                if (null === $decoded) {
                    return null;
                }

                $parts[] = $decoded;
                $heredoc = null;
                $expectString = false;

                continue;
            }

            if ($this->isIgnorableToken($token)) {
                continue;
            }

            if ('(' === $token || ')' === $token) {
                continue;
            }

            if ($expectString) {
                $isString = \is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0];
                $opensHeredoc = \is_array($token) && \T_START_HEREDOC === $token[0];
                if (!$isString && !$opensHeredoc) {
                    return null;
                }

                $firstLine ??= $token[2];
                if (null === $firstTokenOffset) {
                    $tokenIndex = $tokenIndexes[$index] ?? null;
                    if (\is_int($tokenIndex) && isset($tokenOffsets[$tokenIndex])) {
                        $firstTokenOffset = $tokenOffsets[$tokenIndex];
                        $firstTokenColumn = $this->columnFromOffset($content, $firstTokenOffset);
                    }
                }

                if ($opensHeredoc) {
                    $heredoc = [$token[1], ''];

                    continue;
                }

                $parts[] = $this->decodeStringToken($token[1]);
                $expectString = false;

                continue;
            }

            if ('.' === $token) {
                $expectString = true;

                continue;
            }

            return null;
        }

        if ($expectString || null === $firstLine) {
            return null;
        }

        // ('/a/') . 'i' starts at its parenthesis, as php-parser places it.
        $lead = $this->nextSignificantTokenIndex($tokens, 0, \count($tokens));
        $leadOffset = '(' === $tokens[$lead ?? 0] ? ($tokenOffsets[$tokenIndexes[$lead] ?? -1] ?? null) : null;
        if (null !== $leadOffset && null !== $firstTokenOffset) {
            $firstLine -= substr_count($content, "\n", $leadOffset, $firstTokenOffset - $leadOffset);
            $firstTokenOffset = $leadOffset;
            $firstTokenColumn = $this->columnFromOffset($content, $leadOffset);
        }

        $pattern = implode('', $parts);

        // Special handling for regex patterns with flags that might have been concatenated
        // Check if this looks like a regex that might have flags after closing delimiter
        /*
            * if (preg_match('/^([\'"{}\/#~%])([^\'"{\/#~%]*)([\'"{\/\#~%])([A-Za-z]*)$/', $pattern, $matches)) {
            $delimiter = $matches[1];
            $body = $matches[2];
            $endDelimiter = $matches[3];
            $flags = $matches[4];

            // Currently we keep $pattern as-is; this block mainly validates
            // that it already looks like a well-formed /body/flags pattern.
        // }
            */

        return [
            'pattern' => $pattern,
            'line' => $firstLine,
            'offset' => $firstTokenOffset,
            'column' => $firstTokenColumn,
        ];
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<int, int>
     */
    private function buildTokenOffsets(array $tokens): array
    {
        $offsets = [];
        $offset = 0;

        foreach ($tokens as $index => $token) {
            $offsets[$index] = $offset;
            $text = \is_array($token) ? $token[1] : $token;
            $offset += \strlen($text);
        }

        return $offsets;
    }

    private function columnFromOffset(string $content, int $offset): ?int
    {
        if ($offset < 0) {
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
     * The value of a quoted string token, as PHP reads it. The b of a binary
     * string, b'/a/' or B"/a/", changes nothing.
     */
    private function decodeStringToken(string $token): string
    {
        if ('b' === strtolower($token[0] ?? '')) {
            $token = substr($token, 1);
        }

        // A token that is no quoted literal: what lies between its ends.
        return PhpStringLiteral::decode($token) ?? substr($token, 1, -1);
    }

    /**
     * Read a token used as a member name.
     *
     * Any reserved word is a valid method name, so this accepts every token
     * whose text is an identifier rather than T_STRING alone.
     *
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function readIdentifierToken(array|string $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }

        if (1 !== LibraryPcre::match(self::IDENTIFIER_PATTERN, $token[1])) {
            return null;
        }

        return $token[1];
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function readNameToken(array|string $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }

        $id = $token[0];
        if (\T_STRING === $id) {
            return $token[1];
        }

        if (\defined('T_NAME_QUALIFIED') && \T_NAME_QUALIFIED === $id) {
            return $token[1];
        }

        if (\defined('T_NAME_FULLY_QUALIFIED') && \T_NAME_FULLY_QUALIFIED === $id) {
            return $token[1];
        }

        if (\defined('T_NAME_RELATIVE') && \T_NAME_RELATIVE === $id) {
            return $token[1];
        }

        return null;
    }

    private function shouldSkipContent(string $content): bool
    {
        return !$this->registry->matchesContent($content);
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

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isDoubleColonToken(array|string $token): bool
    {
        return \is_array($token) && \T_DOUBLE_COLON === $token[0];
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isDefinitionToken(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], [\T_FUNCTION, \T_FN, \T_NEW], true);
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isObjectOrStaticOperator(array|string $token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        $operators = [\T_DOUBLE_COLON, \T_OBJECT_OPERATOR];
        if (\defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $operators[] = \T_NULLSAFE_OBJECT_OPERATOR;
        }

        return \in_array($token[0], $operators, true);
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isIgnorableToken(array|string $token): bool
    {
        return \is_array($token) && isset(self::IGNORABLE_TOKENS[$token[0]]);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $startIndex, int $totalTokens): ?int
    {
        for ($i = $startIndex; $i < $totalTokens; $i++) {
            if (!$this->isIgnorableToken($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function previousSignificantTokenIndex(array $tokens, int $startIndex): ?int
    {
        for ($i = $startIndex; $i >= 0; $i--) {
            if (!$this->isIgnorableToken($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isDoubleArrowToken(array|string $token): bool
    {
        if (!\is_array($token)) {
            return '=>' === $token;
        }

        return \T_DOUBLE_ARROW === $token[0];
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function closingTokenFor(array|string $token): string
    {
        if (\is_array($token)) {
            // "{$x}" and "${x}" in a string close on "}", "#[" on "]".
            return \T_ATTRIBUTE === $token[0] ? ']' : '}';
        }

        return match ($token) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            default => ')',
        };
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isClosingToken(array|string $token, string $expected): bool
    {
        return $token === $expected;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     *
     * @return array{0: array<int, array{int, string, int}|string>, 1: array<int, int>}
     */
    private function stripOuterParentheses(array $tokens, array $tokenIndexes): array
    {
        $totalTokens = \count($tokens);
        if (0 === $totalTokens) {
            return [$tokens, $tokenIndexes];
        }

        while (true) {
            $startIndex = $this->nextSignificantTokenIndex($tokens, 0, $totalTokens);
            $endIndex = $this->previousSignificantTokenIndex($tokens, $totalTokens - 1);

            if (null === $startIndex || null === $endIndex) {
                break;
            }

            if ('(' !== $tokens[$startIndex] || ')' !== $tokens[$endIndex]) {
                break;
            }

            $depth = 0;
            $wrapsAll = true;
            for ($i = $startIndex; $i <= $endIndex; $i++) {
                $token = $tokens[$i];
                if ('(' === $token) {
                    $depth++;
                } elseif (')' === $token) {
                    $depth--;
                    if (0 === $depth && $i < $endIndex) {
                        $wrapsAll = false;

                        break;
                    }
                }
            }

            if (!$wrapsAll || 0 !== $depth) {
                break;
            }

            $tokens = \array_slice($tokens, $startIndex + 1, $endIndex - $startIndex - 1);
            $tokenIndexes = \array_slice($tokenIndexes, $startIndex + 1, $endIndex - $startIndex - 1);
            $totalTokens = \count($tokens);
        }

        return [$tokens, $tokenIndexes];
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function findArrayStartIndex(array $tokens): ?int
    {
        $totalTokens = \count($tokens);
        $startIndex = $this->nextSignificantTokenIndex($tokens, 0, $totalTokens);
        if (null === $startIndex) {
            return null;
        }

        $token = $tokens[$startIndex];
        if ('[' === $token) {
            return $startIndex;
        }

        if (\is_array($token) && \T_ARRAY === $token[0]) {
            $openParenIndex = $this->nextSignificantTokenIndex($tokens, $startIndex + 1, $totalTokens);
            if (null !== $openParenIndex && '(' === $tokens[$openParenIndex]) {
                return $openParenIndex;
            }
        }

        return null;
    }

    /**
     * The content as PHP reads it, its bytes untouched: preg_match() gets
     * the bytes of the source, valid UTF-8 or not. Null for a binary file,
     * one that holds a NUL byte.
     */
    private function readableContent(string $content): ?string
    {
        return self::holdsNulByte($content) ? null : $content;
    }
}
