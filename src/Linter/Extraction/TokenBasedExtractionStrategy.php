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
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;

/**
 * Token-based extraction strategy mirroring PHPStan's preg_* handling.
 *
 * This relies on a small token state machine to track argument positions
 * and only extracts patterns from constant string expressions.
 *
 * @internal
 */
final class TokenBasedExtractionStrategy implements ExtractorInterface, PatternFunctionAwareInterface
{
    private readonly PatternFunctionRegistry $registry;

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

    public function customGlobalFunctions(): array
    {
        return $this->registry->customGlobalFunctions();
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
     * The patterns of a PHP source held in memory, read as extract() reads
     * a file: an editor's unsaved text is read without the disk. The
     * functions marked #[RegexPattern] are the registry's only, the source
     * is not scanned for them.
     *
     * @param string $file the name the occurrences carry
     *
     * @return list<PatternOccurrence>
     */
    public function extractSource(string $content, string $file = ''): array
    {
        if (!$this->readsContent($content)) {
            return [];
        }

        if (!MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR)) {
            return [PatternOccurrence::unread($file, MemoryBudget::refusal($content, MemoryBudget::TOKENIZE_FACTOR))];
        }

        // PHP hands preg_match() the bytes of its source, valid UTF-8 or not,
        // a NUL byte among them: the tokenizer reads them as they stand.
        $tokens = token_get_all($content);
        $tokenOffsets = PhpTokens::offsets($tokens);
        $closers = PhpTokens::closers($tokens);
        /** @var \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>> $found */
        $found = new \ArrayObject();
        /** @var list<int> $patternArguments */
        $patternArguments = [];
        $totalTokens = \count($tokens);
        $context = new NameResolutionContext();
        /** @var array<int, true> $attributeNames */
        $attributeNames = [];

        for ($i = 0; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || isset(PhpTokens::IGNORABLE[$token[0]]) || isset($attributeNames[$i])) {
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

            if (\T_ATTRIBUTE === $token[0]) {
                foreach ($this->matchAttributes($tokens, $i, $closers, $context, $attributeNames) as $match) {
                    $this->extractFromMatch($match, $tokens, $totalTokens, $file, $tokenOffsets, $content, $closers, $context, $patternArguments, $found);
                }

                continue;
            }

            $match = \T_NEW === $token[0]
                ? $this->matchConstructor($tokens, $i, $totalTokens, $context)
                : $this->matchFunctionCall($tokens, $i, $totalTokens, $context);
            if (null !== $match) {
                $this->extractFromMatch($match, $tokens, $totalTokens, $file, $tokenOffsets, $content, $closers, $context, $patternArguments, $found);
            }
        }

        // Each pattern judged for the versions the guards around it keep.
        return TokenGuards::regions($tokens, $tokenOffsets, $closers, $content)->apply(
            $this->settle(array_values($found->getArrayCopy()), $patternArguments, $tokens, $tokenOffsets, $closers, $file, $content),
        );
    }

    /**
     * Whether extractSource() reads the source at all: one that names none
     * of the calls the registry knows holds no pattern of theirs, and is
     * not tokenized.
     */
    public function readsContent(string $content): bool
    {
        return '' !== $content && !$this->shouldSkipContent($content);
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

        return $this->extractSource($content, $file);
    }

    /**
     * The name of the variable the tokens in [$start, $end) are, alone.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function soleVariable(array $tokens, int $start, int $end): ?string
    {
        $first = PhpTokens::nextSignificant($tokens, $start, $end);
        if (null === $first || null !== PhpTokens::nextSignificant($tokens, $first + 1, $end)) {
            return null;
        }

        $token = $tokens[$first];

        return \is_array($token) && \T_VARIABLE === $token[0] ? $token[1] : null;
    }

    /**
     * The variables the file assigns with a plain "=", whatever the scope.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<string, true>
     */
    private function assignedVariables(array $tokens): array
    {
        $assigned = [];
        foreach ($tokens as $index => $token) {
            if ('=' !== $token) {
                continue;
            }

            $target = PhpTokens::previousSignificant($tokens, $index - 1);
            $targetToken = null === $target ? null : $tokens[$target];
            if (\is_array($targetToken) && \T_VARIABLE === $targetToken[0]) {
                $assigned[$targetToken[1]] = true;
            }
        }

        return $assigned;
    }

    /**
     * Read the pattern arguments of a call the registry knows.
     *
     * @param array{PatternFunction, int}                                          $match            the function and the
     *                                                                                               parenthesis opening its arguments
     * @param array<int, array{int, string, int}|string>                           $tokens
     * @param array<int, int>                                                      $tokenOffsets
     * @param array<int, int>                                                      $closers
     * @param list<int>                                                            $patternArguments
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>> $found
     */
    private function extractFromMatch(array $match, array $tokens, int $totalTokens, string $file, array $tokenOffsets, string $content, array $closers, NameResolutionContext $context, array &$patternArguments, \ArrayObject $found): void
    {
        [$patternFunction, $openParenIndex] = $match;

        // A partial application, preg_match('/re/', ?), is no call, as
        // the parser strategy reads it; the calls in its arguments are.
        if ($this->isPartialApplication($tokens, $openParenIndex + 1, $totalTokens, $closers)) {
            return;
        }

        // A named argument stands for every position it may fill: read once.
        $read = [];
        foreach ($patternFunction->eachArgument() as $function) {
            $this->extractFromCall($tokens, $openParenIndex + 1, $totalTokens, $function, $file, $tokenOffsets, $content, $closers, $context, $read, $patternArguments, $found);
        }
    }

    /**
     * The occurrences in the order of their calls. A pattern no literal
     * gives is read, when the file holds one, in a second pass over the
     * file: what the file proves about its names is known once all of it
     * is read.
     *
     * @param list<PatternOccurrence|PendingPattern<array{int, int}>> $found            a pattern argument that is no
     *                                                                                  literal is its token range
     * @param list<int>                                               $patternArguments where the pattern arguments start
     * @param array<int, array{int, string, int}|string>              $tokens
     * @param array<int, int>                                         $tokenOffsets
     * @param array<int, int>                                         $closers
     *
     * @return list<PatternOccurrence>
     */
    private function settle(array $found, array $patternArguments, array $tokens, array $tokenOffsets, array $closers, string $file, string $content): array
    {
        $pending = $this->pendingRanges($found, $tokens);
        if ([] === $pending) {
            return array_values(array_filter($found, static fn (PatternOccurrence|PendingPattern $item): bool => $item instanceof PatternOccurrence));
        }

        [$variables, $properties] = $this->watchedNames($pending, $tokens);
        $collector = new TokenValueCollector($tokens, $tokenOffsets, $closers, $content, $variables, $properties);
        foreach ($patternArguments as $start) {
            $collector->markPatternArgument($start);
        }

        $values = $this->readValues($collector, $pending, $tokens);
        $resolver = $collector->values();
        // Every read is known before the first is resolved: a value
        // assigned after the last never reaches any.
        foreach ($values as $value) {
            $resolver->read($value);
        }
        $occurrences = [];
        foreach ($found as $item) {
            if ($item instanceof PatternOccurrence) {
                $occurrences[] = $item;

                continue;
            }

            [$start, $end] = $item->place;
            $value = $values[$start.':'.$end] ?? null;
            if (null !== $value && $value->isResolvable()) {
                array_push($occurrences, ...$item->occurrences($resolver, $value, $file, $content));
            }
        }

        return $occurrences;
    }

    /**
     * The token ranges of the held arguments a second pass may resolve,
     * start => end => true: a variable gets a value from an assignment, or
     * none, so one the file never assigns is left out.
     *
     * @param list<PatternOccurrence|PendingPattern<array{int, int}>> $found
     * @param array<int, array{int, string, int}|string>              $tokens
     *
     * @return array<int, non-empty-array<int, true>>
     */
    private function pendingRanges(array $found, array $tokens): array
    {
        $pending = [];
        $assigned = null;
        foreach ($found as $item) {
            if (!$item instanceof PendingPattern) {
                continue;
            }

            [$start, $end] = $item->place;
            $variable = $this->soleVariable($tokens, $start, $end);
            if (null !== $variable) {
                $assigned ??= $this->assignedVariables($tokens);
                if (!isset($assigned[$variable])) {
                    continue;
                }
            }

            $pending[$start][$end] = true;
        }

        return $pending;
    }

    /**
     * The variables the held arguments name, and whether they name a
     * property: the writes to any other change no pattern, and are not
     * followed.
     *
     * @param array<int, non-empty-array<int, true>>     $pending
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{array<string, true>, bool}
     */
    private function watchedNames(array $pending, array $tokens): array
    {
        $variables = [];
        $properties = false;
        foreach ($pending as $start => $ends) {
            for ($i = $start, $last = max(array_keys($ends)); $i < $last; $i++) {
                $token = $tokens[$i];
                if (!\is_array($token)) {
                    continue;
                }

                if (\T_VARIABLE === $token[0]) {
                    $variables[$token[1]] = true;
                } elseif (!$properties && \T_STRING === $token[0]) {
                    $before = PhpTokens::previousSignificant($tokens, $i - 1);
                    $properties = null !== $before && PhpTokens::isObjectOrStaticOperator($tokens[$before]);
                }
            }
        }

        return [$variables, $properties];
    }

    /**
     * What each held argument reads as, keyed "start:end", from a second
     * walk over the file: the same walk as the first pass, so that each
     * name is read in the namespace, the imports, the class and the
     * function it sits in.
     *
     * @param array<int, non-empty-array<int, true>>     $pending
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<string, ValueExpr>
     */
    private function readValues(TokenValueCollector $collector, array $pending, array $tokens): array
    {
        $values = [];
        $context = new NameResolutionContext();
        for ($i = 0, $totalTokens = \count($tokens); $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            $id = \is_array($token) ? $token[0] : $token;
            if (\T_NAMESPACE === $id) {
                $i = $this->readNamespaceDeclaration($tokens, $i, $totalTokens, $context);

                continue;
            }

            if (\T_USE === $id) {
                $next = $this->readUseStatement($tokens, $i, $totalTokens, $context);
                if ($next !== $i) {
                    $i = $next;

                    continue;
                }
            }

            // Most tokens declare, write and hold nothing.
            if (isset(TokenValueCollector::READ[$id])) {
                $collector->visit($i, $context);
            }

            if (isset($pending[$i])) {
                foreach ($pending[$i] as $end => $_) {
                    $values[$i.':'.$end] = $collector->expression($i, $end, $context);
                }
            }
        }

        return $values;
    }

    /**
     * The attributes of the group opening at $index that carry a pattern,
     * each with the parenthesis opening its arguments. Their names are
     * marked, so that they are not read again as function calls.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     * @param array<int, true>                           $attributeNames
     *
     * @return list<array{PatternFunction, int}>
     */
    private function matchAttributes(array $tokens, int $index, array $closers, NameResolutionContext $context, array &$attributeNames): array
    {
        // A group left open holds no item: the loop does not run.
        $close = $closers[$index] ?? $index;
        $matches = [];
        $itemStart = $index + 1;
        for ($i = $index + 1; $i <= $close; $i++) {
            $token = $tokens[$i];
            if (',' !== $token && $i !== $close) {
                if (PhpTokens::opensNesting($token)) {
                    $i = $closers[$i] ?? $close;
                }

                continue;
            }

            $nameIndex = PhpTokens::nextSignificant($tokens, $itemStart, $i);
            $name = null === $nameIndex ? null : PhpTokens::name($tokens[$nameIndex]);
            $itemStart = $i + 1;
            if (null === $nameIndex || null === $name) {
                continue;
            }

            $attributeNames[$nameIndex] = true;
            $open = PhpTokens::nextSignificant($tokens, $nameIndex + 1, $i);
            $function = $this->registry->lookupAttribute($context->resolveClass($name));
            if (null !== $open && '(' === $tokens[$open] && null !== $function) {
                $matches[] = [$function, $open];
            }
        }

        return $matches;
    }

    /**
     * new Class(...), the class resolved as PHP does.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{PatternFunction, int}|null
     */
    private function matchConstructor(array $tokens, int $index, int $totalTokens, NameResolutionContext $context): ?array
    {
        $classIndex = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);
        $class = null === $classIndex ? null : PhpTokens::name($tokens[$classIndex]);
        $open = null === $classIndex ? null : PhpTokens::nextSignificant($tokens, $classIndex + 1, $totalTokens);
        if (null === $class || null === $open || '(' !== $tokens[$open]) {
            return null;
        }

        $function = $this->registry->lookupConstructor($context->resolveClass($class));

        return null === $function ? null : [$function, $open];
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
        $nameIndex = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);
        if (null === $nameIndex) {
            return $index;
        }

        $name = PhpTokens::name($tokens[$nameIndex]);
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
        $cursor = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);
        if (null === $cursor) {
            return $index;
        }

        // Closure capture: use ($foo, $bar)
        if ('(' === $tokens[$cursor]) {
            return $index;
        }

        // "use Str;" in a class body uses a trait, and imports nothing.
        if ($this->usesATrait($tokens, $index)) {
            return $this->skipToStatementEnd($tokens, $cursor, $totalTokens);
        }

        // What the statement imports: classes, functions or constants; an
        // item of a group may say otherwise.
        $kind = \T_CLASS;
        $token = $tokens[$cursor];
        if (\is_array($token) && (\T_CONST === $token[0] || \T_FUNCTION === $token[0])) {
            $kind = $token[0];
            $next = PhpTokens::nextSignificant($tokens, $cursor + 1, $totalTokens);
            if (null === $next) {
                return $index;
            }

            $cursor = $next;
        }

        $prefix = '';
        $name = '';
        $alias = null;
        $expectAlias = false;
        $itemKind = $kind;

        for (; $cursor < $totalTokens; $cursor++) {
            $token = $tokens[$cursor];

            if (PhpTokens::isIgnorable($token)) {
                continue;
            }

            if (';' === $token) {
                $this->commitImport($context, $prefix, $name, $alias, $itemKind);

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
                $this->commitImport($context, $prefix, $name, $alias, $itemKind);
                $prefix = '';
                $name = '';
                $alias = null;
                $expectAlias = false;
                $itemKind = $kind;

                continue;
            }

            if (',' === $token) {
                $this->commitImport($context, $prefix, $name, $alias, $itemKind);
                $name = '';
                $alias = null;
                $expectAlias = false;
                $itemKind = $kind;

                continue;
            }

            // use A\{B, function c, const D}
            if (\is_array($token) && (\T_CONST === $token[0] || \T_FUNCTION === $token[0]) && '' !== $prefix && '' === $name) {
                $itemKind = $token[0];

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

            $read = PhpTokens::name($token);
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

    /**
     * Whether the "use" at $index sits in a brace no namespace opens, the
     * body of a class, an enum or a trait: an import is written at the top
     * of the file or of a braced namespace.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function usesATrait(array $tokens, int $index): bool
    {
        $depth = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ('}' === $token) {
                $depth++;

                continue;
            }

            if ('{' !== $token && !(\is_array($token) && (\T_CURLY_OPEN === $token[0] || \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]))) {
                continue;
            }

            if ($depth > 0) {
                $depth--;

                continue;
            }

            // The brace the "use" sits in: "namespace {" or "namespace A\B {".
            $before = PhpTokens::previousSignificant($tokens, $i - 1);
            if (null !== $before && \is_array($tokens[$before]) && \in_array($tokens[$before][0], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                $before = PhpTokens::previousSignificant($tokens, $before - 1);
            }

            return null === $before || !\is_array($tokens[$before]) || \T_NAMESPACE !== $tokens[$before][0];
        }

        return false;
    }

    private function commitImport(NameResolutionContext $context, string $prefix, string $name, ?string $alias, int $kind): void
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

        if (\T_FUNCTION === $kind) {
            $context->importFunction($alias, $target);

            return;
        }

        if (\T_CONST === $kind) {
            $context->importConstant($alias, $target);

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
        $token = $tokens[$index];

        // static::assertMatchesRegularExpression(...)
        if (\is_array($token) && \T_STATIC === $token[0]) {
            $nextIndex = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);

            return null !== $nextIndex && $this->isDoubleColonToken($tokens[$nextIndex])
                ? $this->matchStaticMethodCall($tokens, $index, $nextIndex, $totalTokens, $context)
                : null;
        }

        // A name after "->" is a T_STRING, whatever word spells it.
        $name = PhpTokens::name($token);
        if (null === $name) {
            return null;
        }

        $nextIndex = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);
        if (null !== $nextIndex && $this->isDoubleColonToken($tokens[$nextIndex])) {
            return $this->matchStaticMethodCall($tokens, $index, $nextIndex, $totalTokens, $context);
        }

        $prevIndex = PhpTokens::previousSignificant($tokens, $index - 1);

        // $this->assertMatchesRegularExpression(...): a method matched by
        // its name.
        if (null !== $prevIndex && \is_array($tokens[$prevIndex]) && \T_OBJECT_OPERATOR === $tokens[$prevIndex][0]) {
            return $this->matchThisMethodCall($tokens, $index, $prevIndex, $nextIndex);
        }

        // From here on we treat this as a plain function call and decide
        // whether it is a registered function.
        if (null !== $prevIndex) {
            $prevToken = $tokens[$prevIndex];
            if ($this->isDefinitionToken($prevToken) || PhpTokens::isObjectOrStaticOperator($prevToken)) {
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
        $methodIndex = PhpTokens::nextSignificant($tokens, $doubleColonIndex + 1, $totalTokens);
        if (null === $methodIndex) {
            return null;
        }

        $methodName = PhpTokens::identifier($tokens[$methodIndex]);
        if (null === $methodName) {
            return null;
        }

        $openParenIndex = PhpTokens::nextSignificant($tokens, $methodIndex + 1, $totalTokens);
        if (null === $openParenIndex || '(' !== $tokens[$openParenIndex]) {
            return null;
        }

        $classToken = $tokens[$classIndex];
        $className = \is_array($classToken) && \T_STATIC === $classToken[0] ? 'static' : PhpTokens::name($classToken);
        if (null === $className) {
            return null;
        }

        // self::, static::, parent::: the class is the test's, PHPUnit's
        // assertions are matched by name.
        $patternFunction = \in_array(Ascii::lower($className), ['self', 'static', 'parent'], true)
            ? $this->registry->lookupNamedMethod($methodName)
            : $this->registry->lookupMethod($context->resolveClass($className), $methodName);
        if (null === $patternFunction) {
            return null;
        }

        return [$patternFunction, $openParenIndex];
    }

    /**
     * $this->method(...), the method matched by its name.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{PatternFunction, int}|null
     */
    private function matchThisMethodCall(array $tokens, int $methodIndex, int $operatorIndex, ?int $openParenIndex): ?array
    {
        // The name first: most method calls are no pattern function.
        $method = PhpTokens::identifier($tokens[$methodIndex]);
        $patternFunction = null === $method ? null : $this->registry->lookupNamedMethod($method);
        if (null === $patternFunction) {
            return null;
        }

        $receiver = PhpTokens::previousSignificant($tokens, $operatorIndex - 1);
        if (null === $receiver || null === $openParenIndex || '(' !== $tokens[$openParenIndex]
            || !\is_array($tokens[$receiver]) || \T_VARIABLE !== $tokens[$receiver][0] || '$this' !== $tokens[$receiver][1]) {
            return null;
        }

        // $other->$this->method() reads a property named by $this.
        $beforeReceiver = PhpTokens::previousSignificant($tokens, $receiver - 1);
        if (null !== $beforeReceiver && (PhpTokens::isObjectOrStaticOperator($tokens[$beforeReceiver]) || '$' === $tokens[$beforeReceiver])) {
            return null;
        }

        return [$patternFunction, $openParenIndex];
    }

    /**
     * @param array<int, array{int, string, int}|string>                                $tokens
     * @param array<int, int>                                                           $tokenOffsets
     * @param array<int, int>                                                           $closers          the closing index of each bracket, from matchBrackets()
     * @param list<int>                                                                 $read             where the arguments read for this call start
     * @param list<int>                                                                 $patternArguments where every pattern argument read starts
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>>|null $sink             receives, in order,
     *                                                                                                    the literal occurrences and the
     *                                                                                                    token range of each pattern
     *                                                                                                    argument no literal gives
     *
     * @return list<PatternOccurrence> the literal occurrences
     */
    private function extractFromCall(
        array $tokens,
        int $startIndex,
        int $totalTokens,
        PatternFunction $patternFunction,
        string $file,
        array $tokenOffsets,
        string $content,
        array $closers,
        NameResolutionContext $context,
        array &$read = [],
        array &$patternArguments = [],
        ?\ArrayObject $sink = null,
    ): array {
        // A call being typed, its list left open, is read as far as it goes;
        // an argument itself left open, an array or a callback cut short,
        // makes the call unknown.
        if (!isset($closers[$startIndex - 1])) {
            for ($i = $startIndex; $i < $totalTokens; $i++) {
                if (PhpTokens::opensNesting($tokens[$i]) && !isset($closers[$i])) {
                    return [];
                }
            }
        }

        $argument = $this->findArgument($tokens, $startIndex, $totalTokens, $patternFunction->argumentIndex, $patternFunction->parameterNames, $closers);
        if (null === $argument || \in_array($argument[0], $read, true)) {
            return [];
        }

        $read[] = $argument[0];
        $patternArguments[] = $argument[0];

        // Nette's Strings::replace() reads the values of the array when the
        // replacement is an object or an array: a callable.
        if (null !== $patternFunction->replacementIndex) {
            $replacement = $this->findArgument($tokens, $startIndex, $totalTokens, $patternFunction->replacementIndex, PatternFunction::REPLACEMENT_PARAMETER_NAMES, $closers);
            if (null !== $replacement && $this->isObjectOrArray($tokens, $replacement[0], $replacement[1], $closers, $context)) {
                $patternFunction = $patternFunction->readingValues();
            }
        }

        // Nette's utf8: true appends "u" to every pattern; anything but
        // true or false leaves its flags unknown.
        if (null !== $patternFunction->utf8Argument) {
            $utf8 = $this->utf8($tokens, $startIndex, $totalTokens, $patternFunction->utf8Argument, $closers);
            if (null === $utf8) {
                return [];
            }

            if ($utf8) {
                $patternFunction = $patternFunction->withPatternSuffix('u');
            }
        }

        // filter_var() compiles the option only under FILTER_VALIDATE_REGEXP.
        if (null !== $patternFunction->filterArgument) {
            [$index, $parameter, $constant] = $patternFunction->filterArgument;
            $filter = $this->findArgument($tokens, $startIndex, $totalTokens, $index, [$parameter], $closers);
            $name = null === $filter ? null : $this->soleName($tokens, $filter[0], $filter[1]);
            if (null === $name || !\in_array($constant, $context->resolveConstant($name), true)) {
                return [];
            }
        }

        $range = $this->optionRange($tokens, $argument[0], $argument[1], $patternFunction, $closers, $tokenOffsets, $content);
        if (null === $range) {
            return [];
        }

        [$argumentTokens, $argumentIndexes] = PhpTokens::slice($tokens, $range[0], $range[1]);

        return $this->extractFromArgumentTokens($argumentTokens, $argumentIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
    }

    /**
     * Where an argument holds the pattern: the argument itself, or, for an
     * array literal, the item its option path leads to (the last of a
     * repeated key, as PHP keeps it).
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array{int, int}|null
     */
    private function optionRange(array $tokens, int $start, int $end, PatternFunction $function, array $closers, array $tokenOffsets, string $content): ?array
    {
        if ([] === $function->optionPath) {
            return [$start, $end];
        }

        foreach ($function->optionPath as $key) {
            $items = $this->arrayItems($tokens, $start, $end, $closers);
            if (null === $items) {
                // Only the first level may be the pattern itself.
                return $function->optionPathRequired || $key !== $function->optionPath[0] ? null : [$start, $end];
            }

            $found = null;
            foreach ($items as [$keyRange, $valueRange]) {
                if (null === $keyRange) {
                    // A spread may hold the key, replacing what came before.
                    $first = PhpTokens::nextSignificant($tokens, $valueRange[0], $valueRange[1]);
                    if (null !== $first && \is_array($tokens[$first]) && \T_ELLIPSIS === $tokens[$first][0]) {
                        $found = null;
                    }

                    continue;
                }

                [$keyTokens, $keyIndexes] = PhpTokens::slice($tokens, $keyRange[0], $keyRange[1]);
                [$keyTokens, $keyIndexes] = $this->stripOuterParentheses($keyTokens, $keyIndexes);
                $literal = $this->parseConstantStringExpression($keyTokens, $keyIndexes, $tokenOffsets, $content);
                if (null !== $literal && $key === $literal['pattern']) {
                    $found = $valueRange;
                }
            }

            if (null === $found) {
                return null;
            }

            [$start, $end] = $found;
        }

        return [$start, $end];
    }

    /**
     * The items of the array literal that is the whole of [$start, $end),
     * each its key range, if any, and its value range; null when the range
     * is no array literal.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     *
     * @return list<array{array{int, int}|null, array{int, int}}>|null
     */
    private function arrayItems(array $tokens, int $start, int $end, array $closers): ?array
    {
        $first = PhpTokens::nextSignificant($tokens, $start, $end);
        $last = PhpTokens::previousSignificant($tokens, $end - 1);
        while (null !== $first && null !== $last && '(' === $tokens[$first] && ($closers[$first] ?? null) === $last) {
            $first = PhpTokens::nextSignificant($tokens, $first + 1, $last);
            $last = PhpTokens::previousSignificant($tokens, $last - 1);
        }

        if (null === $first || null === $last) {
            return null;
        }

        $open = $first;
        if (\is_array($tokens[$first]) && \T_ARRAY === $tokens[$first][0]) {
            $open = PhpTokens::nextSignificant($tokens, $first + 1, $last + 1);
            if (null === $open || '(' !== $tokens[$open]) {
                return null;
            }
        } elseif ('[' !== $tokens[$first]) {
            return null;
        }

        if (($closers[$open] ?? null) !== $last) {
            return null;
        }

        $items = [];
        $itemStart = $open + 1;
        for ($i = $open + 1; $i <= $last; $i++) {
            $token = $tokens[$i];
            if (',' !== $token && $i !== $last) {
                if (PhpTokens::opensNesting($token)) {
                    $i = $closers[$i] ?? $last;
                }

                continue;
            }

            $arrow = null;
            for ($j = $itemStart; $j < $i; $j++) {
                if ($this->isDoubleArrowToken($tokens[$j])) {
                    $arrow = $j;

                    break;
                }

                if (PhpTokens::opensNesting($tokens[$j])) {
                    $j = $closers[$j] ?? $i;
                }
            }

            if (null !== PhpTokens::nextSignificant($tokens, $itemStart, $i)) {
                $items[] = null === $arrow ? [null, [$itemStart, $i]] : [[$itemStart, $arrow], [$arrow + 1, $i]];
            }

            $itemStart = $i + 1;
        }

        return $items;
    }

    /**
     * The name an argument in [$start, $end) is, alone: a constant.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function soleName(array $tokens, int $start, int $end): ?string
    {
        // (FILTER_VALIDATE_REGEXP) is the constant: PHP drops the parentheses.
        [$slice] = $this->stripOuterParentheses(...PhpTokens::slice($tokens, $start, $end));
        $significant = array_values(array_filter($slice, static fn (array|string $token): bool => !PhpTokens::isIgnorable($token)));

        return 1 === \count($significant) ? PhpTokens::name($significant[0]) : null;
    }

    /**
     * Whether a call is a partial application: one of its arguments, by
     * position or by name, is only a placeholder, "?" or "...".
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private function isPartialApplication(array $tokens, int $startIndex, int $totalTokens, array $closers): bool
    {
        $argumentStart = $startIndex;
        for ($i = $startIndex; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (')' === $token || ',' === $token) {
                $argument = $this->readArgument($tokens, $argumentStart, $i);
                $value = null === $argument ? null : PhpTokens::nextSignificant($tokens, $argument['valueStart'], $i);
                if (null !== $value && ('?' === $tokens[$value] || (\is_array($tokens[$value]) && \T_ELLIPSIS === $tokens[$value][0]))
                    && null === PhpTokens::nextSignificant($tokens, $value + 1, $i)) {
                    return true;
                }
                if (')' === $token) {
                    return false;
                }

                $argumentStart = $i + 1;
            } elseif (PhpTokens::opensNesting($token)) {
                $i = $closers[$i] ?? $totalTokens;
            }
        }

        return false;
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
     * @param list<string>                               $names   parameter names, compared as PHP does, case included
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
                    // A spread makes every later position unknowable; a
                    // name still passes the argument.
                    if ($argument['spread']) {
                        $position = \PHP_INT_MIN;
                    } elseif ($position === $targetArgIndex) {
                        return [$argument['valueStart'], $i];
                    }

                    $position++;
                } elseif (null !== $argument && null === $named && \in_array($argument['name'], $names, true)) {
                    $named = [$argument['valueStart'], $i];
                }

                if ($closesCall) {
                    return $named;
                }

                $argumentStart = $i + 1;
            } elseif (PhpTokens::opensNesting($token)) {
                // Step over the bracket; one left open runs to the end.
                $i = $closers[$i] ?? $totalTokens;
            }

            $i++;
        }
    }

    /**
     * What the utf8 argument of a nette/utils call is, by position or by
     * name: false when left out; null when it is no literal true or false,
     * or a spread may hold it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array{int, string}                         $utf8    its position and its name
     * @param array<int, int>                            $closers
     */
    private function utf8(array $tokens, int $startIndex, int $totalTokens, array $utf8, array $closers): ?bool
    {
        $position = 0;
        $value = null;
        $argumentStart = $startIndex;
        for ($i = $startIndex; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (')' === $token || ',' === $token) {
                $argument = $this->readArgument($tokens, $argumentStart, $i);
                if (null !== $argument && $argument['spread']) {
                    return null;
                }

                if (null !== $argument && (null === $argument['name'] ? $position++ === $utf8[0] : $utf8[1] === $argument['name'])) {
                    $value = [$argument['valueStart'], $i];
                }

                if (')' === $token) {
                    break;
                }

                $argumentStart = $i + 1;
            } elseif (PhpTokens::opensNesting($token)) {
                $i = $closers[$i] ?? $totalTokens;
            }
        }

        $name = null === $value ? 'false' : $this->soleName($tokens, $value[0], $value[1]);
        $literal = null === $name || 1 < substr_count($name, '\\') ? null : Ascii::lower(ltrim($name, '\\'));

        return 'true' === $literal ? true : ('false' === $literal ? false : null);
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
        $first = PhpTokens::nextSignificant($tokens, $start, $end);
        if (null === $first) {
            return null;
        }

        $token = $tokens[$first];
        if (\is_array($token) && \T_ELLIPSIS === $token[0]) {
            return ['name' => null, 'spread' => true, 'valueStart' => $start];
        }

        // "pattern: '/re/'": an identifier, any reserved word included,
        // followed by a single colon.
        $name = PhpTokens::identifier($token);
        $colon = null === $name ? null : PhpTokens::nextSignificant($tokens, $first + 1, $end);
        if (null !== $colon && ':' === $tokens[$colon]) {
            return ['name' => $name, 'spread' => false, 'valueStart' => $colon + 1];
        }

        return ['name' => null, 'spread' => false, 'valueStart' => $start];
    }

    /**
     * Whether the argument in [$start, $end) is an object or an array
     * whatever its value, as Nette's Strings::replace() tests its
     * replacement: a closure, an arrow function, a first-class callable, an
     * array, a new object, $this, an (object) or (array) cast, a clone, or
     * Closure::fromCallable(). Each counts only when it is the whole
     * argument: $cb ?? Ascii::upper(...) is not one.
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
        $first = PhpTokens::nextSignificant($tokens, $start, $end) ?? $end;
        $last = PhpTokens::previousSignificant($tokens, $end - 1) ?? $start;
        while ('(' === ($tokens[$first] ?? null) && ($closers[$first] ?? null) === $last) {
            $first = PhpTokens::nextSignificant($tokens, $first + 1, $last) ?? $last;
            $last = PhpTokens::previousSignificant($tokens, $last - 1) ?? $first;
        }

        $token = $tokens[$first] ?? ';';
        $next = PhpTokens::nextSignificant($tokens, $first + 1, $last + 1) ?? $last + 1;

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
            $index = PhpTokens::nextSignificant($tokens, $after + 1, $last + 1) ?? $last + 1;
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
            if (PhpTokens::opensNesting($tokens[$i])) {
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
            $i = (PhpTokens::opensNesting($tokens[$i]) ? ($closers[$i] ?? $last) : $i) + 1;
        }

        return $closers[$i] ?? null;
    }

    /**
     * Whether $tokens[$from..$to] is a variable, a name or a member chain on
     * them, with nothing else: $a, $this->b['c'], Foo::$d, Ascii::upper(...).
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
            if (PhpTokens::isIgnorable($token) || '$' === $token) {
                continue;
            }

            if (PhpTokens::opensNesting($token)) {
                $i = $closers[$i] ?? $to + 1;
                $afterOperator = false;

                continue;
            }

            if ($afterOperator ? null === PhpTokens::identifier($token) && !$this->isVariableToken($token) : !$this->startsMemberChain($token)) {
                return false;
            }

            $afterOperator = PhpTokens::isObjectOrStaticOperator($token);
        }

        return $from <= $to && $i === $to + 1;
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function startsMemberChain(array|string $token): bool
    {
        return $this->isVariableToken($token) || PhpTokens::isObjectOrStaticOperator($token)
            || null !== PhpTokens::name($token) || (\is_array($token) && \T_STATIC === $token[0]);
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
        $ellipsis = PhpTokens::previousSignificant($tokens, $last - 1) ?? $last;
        $open = PhpTokens::previousSignificant($tokens, $ellipsis - 1) ?? $ellipsis;
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
        $class = PhpTokens::name($tokens[$first]);
        if (null === $class || 'closure' !== Ascii::lower($context->resolveClass($class)) || !$this->isDoubleColonToken($tokens[$next] ?? '')) {
            return false;
        }

        $method = PhpTokens::nextSignificant($tokens, $next + 1, $last + 1) ?? $last;
        $open = PhpTokens::nextSignificant($tokens, $method + 1, $last + 1) ?? $last;

        return 'fromcallable' === Ascii::lower(PhpTokens::identifier($tokens[$method]) ?? '')
            && '(' === $tokens[$open] && ($closers[$open] ?? null) === $last;
    }

    /**
     * @param array<int, array{int, string, int}|string>                                $tokens
     * @param array<int, int>                                                           $tokenIndexes
     * @param array<int, int>                                                           $tokenOffsets
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>>|null $sink
     *
     * @return list<PatternOccurrence>
     */
    private function extractFromArgumentTokens(
        array $tokens,
        array $tokenIndexes,
        array $tokenOffsets,
        string $content,
        string $file,
        PatternFunction $patternFunction,
        ?\ArrayObject $sink = null,
    ): array {
        [$stripped, $strippedIndexes] = $this->stripOuterParentheses($tokens, $tokenIndexes);

        // preg_replace(['/a/', '/b/'], ...) and preg_replace_callback_array(['/a/' => $fn])
        // hold several patterns in one argument.
        if (null !== $this->findArrayStartIndex($stripped)) {
            return $this->extractFromArrayLiteral($stripped, $strippedIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
        }

        // Outside an array literal a keys-function still gets one pattern:
        // Nette's Strings::replace($s, '/re/', 'x') takes a plain string.
        $patternInfo = $this->parseRegexExpression($stripped, $strippedIndexes, $tokenOffsets, $content)
            ?? $this->parseConstantStringExpression($stripped, $strippedIndexes, $tokenOffsets, $content);

        if (null === $patternInfo) {
            $this->holdPattern($stripped, $tokenIndexes, $patternFunction, $sink);

            return [];
        }

        if ('' === $patternInfo['pattern']) {
            return [];
        }

        $occurrence = $this->createOccurrence($patternInfo, $file, $patternFunction);
        $sink?->append($occurrence);

        return [$occurrence];
    }

    /**
     * Hold a pattern no literal gives as its token range, for the names it
     * may go through: a concatenation, a variable, $this->name, a constant
     * or a class constant. Any other expression names no value the file
     * proves.
     *
     * @param array<int, array{int, string, int}|string>                                $tokens       the expression, its outer parentheses stripped
     * @param array<int, int>                                                           $tokenIndexes the indexes of its tokens in the file
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>>|null $sink
     */
    private function holdPattern(array $tokens, array $tokenIndexes, PatternFunction $patternFunction, ?\ArrayObject $sink): void
    {
        if (null !== $sink && [] !== $tokenIndexes && $this->mayName($tokens)) {
            $sink->append(new PendingPattern([min($tokenIndexes), max($tokenIndexes) + 1], $patternFunction));
        }
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function mayName(array $tokens): bool
    {
        $significant = array_values(array_filter($tokens, static fn (array|string $token): bool => !PhpTokens::isIgnorable($token)));
        if (\in_array('.', $significant, true)) {
            return true;
        }

        [$first, $second, $third] = $significant + [null, null, null];
        $count = \count($significant);
        if (1 === $count && \is_array($first)) {
            $name = PhpTokens::name($first);

            return \T_VARIABLE === $first[0] ? '$this' !== $first[1] : null !== $name && !\in_array(Ascii::lower(ltrim($name, '\\')), ['true', 'false', 'null'], true);
        }

        if (3 !== $count || !\is_array($first) || !\is_array($second) || !\is_array($third)) {
            return false;
        }

        if (\T_DOUBLE_COLON === $second[0]) {
            return \T_STATIC === $first[0] || null !== PhpTokens::name($first);
        }

        return \T_VARIABLE === $first[0] && '$this' === $first[1] && \T_OBJECT_OPERATOR === $second[0];
    }

    /**
     * Read every pattern out of an array literal, taking either its keys or
     * its values depending on the call being analysed.
     *
     * @param array<int, array{int, string, int}|string>                                $tokens
     * @param array<int, int>                                                           $tokenIndexes
     * @param array<int, int>                                                           $tokenOffsets
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>>|null $sink
     *
     * @return list<PatternOccurrence>
     */
    private function extractFromArrayLiteral(
        array $tokens,
        array $tokenIndexes,
        array $tokenOffsets,
        string $content,
        string $file,
        PatternFunction $patternFunction,
        ?\ArrayObject $sink = null,
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

            if (PhpTokens::isIgnorable($token)) {
                if ($collecting) {
                    $segmentTokens[] = $token;
                    $segmentTokenIndexes[] = $tokenIndex;
                }

                continue;
            }

            if (\is_array($token) && \T_ARRAY === $token[0]) {
                $nextIndex = PhpTokens::nextSignificant($tokens, $i + 1, $totalTokens);
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

            if (PhpTokens::opensNesting($token)) {
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
                        $this->appendOccurrenceFromSegment($occurrences, $segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
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
                    $this->appendOccurrenceFromSegment($occurrences, $segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
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
                    $this->appendOccurrenceFromSegment($occurrences, $segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
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
        $significant = array_values(array_filter($tokens, static fn (array|string $token): bool => !PhpTokens::isIgnorable($token)));

        // An int, a float, true or false, signed or not: PHP stores an int.
        $sign = 0;
        while ('+' === ($significant[$sign] ?? null) || '-' === ($significant[$sign] ?? null)) {
            $sign++;
        }

        $scalar = $significant[$sign] ?? null;
        if (\is_array($scalar) && $sign + 1 === \count($significant)) {
            $isNumber = \T_LNUMBER === $scalar[0] || \T_DNUMBER === $scalar[0];
            $isBool = 0 === $sign && null !== PhpTokens::name($scalar) && \in_array(Ascii::lower(ltrim($scalar[1], '\\')), ['true', 'false'], true);
            if ($isNumber || $isBool) {
                return false;
            }
        }

        $key = $this->parseConstantStringExpression($tokens, $tokenIndexes, $tokenOffsets, $content);

        return null === $key || PatternFunction::isStringKey($key['pattern']);
    }

    /**
     * @param list<PatternOccurrence>                                                   $occurrences
     * @param array<int, array{int, string, int}|string>                                $tokens
     * @param array<int, int>                                                           $tokenIndexes
     * @param array<int, int>                                                           $tokenOffsets
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>>|null $sink
     */
    private function appendOccurrenceFromSegment(
        array &$occurrences,
        array $tokens,
        array $tokenIndexes,
        array $tokenOffsets,
        string $content,
        string $file,
        PatternFunction $patternFunction,
        ?\ArrayObject $sink = null,
    ): void {
        [$tokens, $tokenIndexes] = $this->stripOuterParentheses($tokens, $tokenIndexes);
        $patternInfo = $this->parseConstantStringExpression($tokens, $tokenIndexes, $tokenOffsets, $content);
        if (null === $patternInfo) {
            $this->holdPattern($tokens, $tokenIndexes, $patternFunction, $sink);

            return;
        }

        if ('' === $patternInfo['pattern']) {
            return;
        }

        $occurrence = $this->createOccurrence($patternInfo, $file, $patternFunction);
        $occurrences[] = $occurrence;
        $sink?->append($occurrence);
    }

    /**
     * @param array{pattern: string, line: int, offset?: int|null, column?: int|null} $patternInfo
     */
    private function createOccurrence(array $patternInfo, string $file, PatternFunction $patternFunction): PatternOccurrence
    {
        return new PatternOccurrence(
            $patternInfo['pattern'].$patternFunction->patternSuffix,
            $file,
            $patternInfo['line'],
            $patternFunction->source(),
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

            if (PhpTokens::isIgnorable($token)) {
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
                        $firstTokenColumn = PendingPattern::column($content, $firstTokenOffset);
                    }
                }

                if ($opensHeredoc) {
                    $heredoc = [$token[1], ''];

                    continue;
                }

                $parts[] = PhpTokens::stringValue($token[1]);
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
        $lead = PhpTokens::nextSignificant($tokens, 0, \count($tokens));
        $leadOffset = '(' === $tokens[$lead ?? 0] ? ($tokenOffsets[$tokenIndexes[$lead] ?? -1] ?? null) : null;
        if (null !== $leadOffset && null !== $firstTokenOffset) {
            $firstLine -= substr_count($content, "\n", $leadOffset, $firstTokenOffset - $leadOffset);
            $firstTokenOffset = $leadOffset;
            $firstTokenColumn = PendingPattern::column($content, $leadOffset);
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
            $startIndex = PhpTokens::nextSignificant($tokens, 0, $totalTokens);
            $endIndex = PhpTokens::previousSignificant($tokens, $totalTokens - 1);

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
        $startIndex = PhpTokens::nextSignificant($tokens, 0, $totalTokens);
        if (null === $startIndex) {
            return null;
        }

        $token = $tokens[$startIndex];
        if ('[' === $token) {
            return $startIndex;
        }

        if (\is_array($token) && \T_ARRAY === $token[0]) {
            $openParenIndex = PhpTokens::nextSignificant($tokens, $startIndex + 1, $totalTokens);
            if (null !== $openParenIndex && '(' === $tokens[$openParenIndex]) {
                return $openParenIndex;
            }
        }

        return null;
    }
}
