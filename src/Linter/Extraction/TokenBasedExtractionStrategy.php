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
                $i = TokenNameResolver::readNamespace($tokens, $i, $totalTokens, $context);

                continue;
            }

            if (\T_USE === $token[0]) {
                $i = TokenNameResolver::readUse($tokens, $i, $totalTokens, $context);

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
        if (CallArguments::isPartialApplication($tokens, $openParenIndex + 1, $totalTokens, $closers)) {
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
                $i = TokenNameResolver::readNamespace($tokens, $i, $totalTokens, $context);

                continue;
            }

            if (\T_USE === $id) {
                $next = TokenNameResolver::readUse($tokens, $i, $totalTokens, $context);
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

            return null !== $nextIndex && PhpTokens::isDoubleColon($tokens[$nextIndex])
                ? $this->matchStaticMethodCall($tokens, $index, $nextIndex, $totalTokens, $context)
                : null;
        }

        // A name after "->" is a T_STRING, whatever word spells it.
        $name = PhpTokens::name($token);
        if (null === $name) {
            return null;
        }

        $nextIndex = PhpTokens::nextSignificant($tokens, $index + 1, $totalTokens);
        if (null !== $nextIndex && PhpTokens::isDoubleColon($tokens[$nextIndex])) {
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
     * @param array<int, int>                                                           $closers          the closing index of each bracket, from PhpTokens::closers()
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

        $argument = CallArguments::find($tokens, $startIndex, $totalTokens, $patternFunction->argumentIndex, $patternFunction->parameterNames, $closers);
        if (null === $argument || \in_array($argument[0], $read, true)) {
            return [];
        }

        $read[] = $argument[0];
        $patternArguments[] = $argument[0];

        // Nette's Strings::replace() reads the values of the array when the
        // replacement is an object or an array: a callable.
        if (null !== $patternFunction->replacementIndex) {
            $replacement = CallArguments::find($tokens, $startIndex, $totalTokens, $patternFunction->replacementIndex, PatternFunction::REPLACEMENT_PARAMETER_NAMES, $closers);
            if (null !== $replacement && CallArguments::isObjectOrArray($tokens, $replacement[0], $replacement[1], $closers, $context)) {
                $patternFunction = $patternFunction->readingValues();
            }
        }

        // Nette's utf8: true appends "u" to every pattern; anything but
        // true or false leaves its flags unknown.
        if (null !== $patternFunction->utf8Argument) {
            $utf8 = CallArguments::utf8($tokens, $startIndex, $totalTokens, $patternFunction->utf8Argument, $closers);
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
            $filter = CallArguments::find($tokens, $startIndex, $totalTokens, $index, [$parameter], $closers);
            $name = null === $filter ? null : CallArguments::soleName($tokens, $filter[0], $filter[1]);
            if (null === $name || !\in_array($constant, $context->resolveConstant($name), true)) {
                return [];
            }
        }

        $range = CallArguments::optionRange($tokens, $argument[0], $argument[1], $patternFunction, $closers, $tokenOffsets, $content);
        if (null === $range) {
            return [];
        }

        [$argumentTokens, $argumentIndexes] = PhpTokens::slice($tokens, $range[0], $range[1]);

        return $this->extractFromArgumentTokens($argumentTokens, $argumentIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
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
        [$stripped, $strippedIndexes] = StringExpressionReader::stripParentheses($tokens, $tokenIndexes);

        // preg_replace(['/a/', '/b/'], ...) and preg_replace_callback_array(['/a/' => $fn])
        // hold several patterns in one argument.
        if (null !== $this->findArrayStartIndex($stripped)) {
            return $this->extractFromArrayLiteral($stripped, $strippedIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
        }

        // Outside an array literal a keys-function still gets one pattern:
        // Nette's Strings::replace($s, '/re/', 'x') takes a plain string.
        $patternInfo = StringExpressionReader::readPattern($stripped, $strippedIndexes, $tokenOffsets, $content)
            ?? StringExpressionReader::read($stripped, $strippedIndexes, $tokenOffsets, $content);

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

            // An array() item keeps its keyword: it is an array, as a []
            // item is, and its parenthesis opens a nesting as any other.
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

            if ($atTopLevel && PhpTokens::isDoubleArrow($token)) {
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
        [$tokens, $tokenIndexes] = StringExpressionReader::stripParentheses($tokens, $tokenIndexes);
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

        $key = StringExpressionReader::read($tokens, $tokenIndexes, $tokenOffsets, $content);

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
        [$tokens, $tokenIndexes] = StringExpressionReader::stripParentheses($tokens, $tokenIndexes);
        $patternInfo = StringExpressionReader::read($tokens, $tokenIndexes, $tokenOffsets, $content);
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
    private function isDefinitionToken(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], [\T_FUNCTION, \T_FN, \T_NEW], true);
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
