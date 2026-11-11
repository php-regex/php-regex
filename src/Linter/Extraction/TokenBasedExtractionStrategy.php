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
use PHPRegex\Parser\Resolution\ArgumentState;
use PHPRegex\Parser\Text\Ascii;

/**
 * Token-based extraction strategy mirroring PHPStan's preg_* handling.
 *
 * It walks the tokens of a file for the calls the registry knows and reads
 * the pattern each passes; the readers it asks are each one job of that
 * walk: TokenNameResolver for the namespace and the imports, CallArguments
 * for which argument holds the pattern, ArrayLiteralPatterns for the items
 * of an array of patterns, StringExpressionReader for what a constant
 * string expression folds to. A pattern no literal gives is held, and read
 * by TokenValueCollector in a second walk of the file. Every pattern
 * argument is held with it, so that each can say what is known of it.
 *
 * @internal
 */
final class TokenBasedExtractionStrategy implements ArgumentAwareInterface, ExtractorInterface, PatternFunctionAwareInterface, ProjectConstantsAwareInterface
{
    private readonly PatternFunctionRegistry $registry;

    /**
     * @param array<int, string>    $customFunctions Additional functions/static methods to check (e.g., 'MyClass::customRegexCheck')
     * @param ProjectConstants|null $project         the constants of the project's other files, when read before
     */
    public function __construct(
        array $customFunctions = [],
        ?PatternFunctionRegistry $registry = null,
        private readonly ?ProjectConstants $project = null
    ) {
        $this->registry = ($registry ?? PatternFunctionRegistry::defaults())->withCustomFunctions($customFunctions);
    }

    public function withPatternFunctions(array $specs, array $plain = []): static
    {
        return new self([], $this->registry->withDeclaredFunctions($specs, $plain)->withDeclarationsRead(), $this->project);
    }

    public function withProjectConstants(ProjectConstants $constants): static
    {
        return new self([], $this->registry, $constants);
    }

    public function customGlobalFunctions(): array
    {
        return $this->registry->customGlobalFunctions();
    }

    public function extract(array $files): array
    {
        return $this->forFiles($files)->extractFiles($files, false);
    }

    public function extractWithArguments(array $files): array
    {
        return $this->forFiles($files)->extractFiles($files, true);
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
        return $this->readSource($content, $file, false);
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
     * The strategy that reads the files: used on its own, the functions
     * the files mark with #[RegexPattern] join the registry for this run.
     *
     * @param array<string> $files
     */
    private function forFiles(array $files): self
    {
        $specs = $this->registry->declarationsRead() ? [] : PatternAttributeScanner::specs($files);

        return [] === $specs ? $this : new self([], $this->registry->withDeclaredFunctions($specs), $this->project);
    }

    /**
     * The patterns of a source, and, with $arguments, its pattern
     * arguments.
     *
     * @return list<PatternOccurrence>
     */
    private function readSource(string $content, string $file, bool $arguments): array
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
            $this->settle(array_values($found->getArrayCopy()), $patternArguments, $tokens, $tokenOffsets, $closers, $file, $content, $arguments),
            $file,
        );
    }

    /**
     * @param array<string> $files
     *
     * @return list<PatternOccurrence>
     */
    private function extractFiles(array $files, bool $arguments): array
    {
        $occurrences = [];
        foreach ($files as $file) {
            $this->appendOccurrences($occurrences, $this->extractFromFile($file, $arguments));
        }

        return $occurrences;
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function extractFromFile(string $file, bool $arguments): array
    {
        if (!is_file($file)) {
            return [];
        }

        $content = is_readable($file) ? @file_get_contents($file) : false;
        if (false === $content) {
            return [PatternOccurrence::unread($file, 'Not linted: the file could not be read.')];
        }

        return $this->readSource($content, $file, $arguments);
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
     * The variables the file assigns, whatever the scope, with a plain
     * "=" or as the target of a foreach, each with the tokens its values
     * are read from: [$from, $to), what follows the "=" up to the end of
     * its statement, $to left null until it is asked for, or what the
     * loop iterates.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     *
     * @return array<string, non-empty-list<array{int, int|null}>>
     */
    private function assignedVariables(array $tokens, array $closers): array
    {
        $assigned = [];
        foreach ($tokens as $index => $token) {
            if ('=' === $token) {
                $target = PhpTokens::previousSignificant($tokens, $index - 1);
                $targetToken = null === $target ? null : $tokens[$target];
                if (\is_array($targetToken) && \T_VARIABLE === $targetToken[0]) {
                    $assigned[$targetToken[1]][] = [$index + 1, null];
                }

                continue;
            }

            if (!\is_array($token) || \T_FOREACH !== $token[0]) {
                continue;
            }

            // foreach (<iterated> as $key => $value): each target that
            // is a variable takes its values from what is iterated.
            $open = PhpTokens::nextSignificant($tokens, $index + 1, \count($tokens));
            $close = null === $open ? null : $closers[$open] ?? null;
            if (null === $open || null === $close) {
                continue;
            }

            $as = null;
            for ($i = $open + 1; $i < $close; $i++) {
                $inner = $tokens[$i];
                if (\is_array($inner) && \T_AS === $inner[0]) {
                    $as = $i;
                } elseif (null !== $as && \is_array($inner) && \T_VARIABLE === $inner[0]) {
                    $assigned[$inner[1]][] = [$open + 1, $as];
                } elseif (PhpTokens::opensNesting($inner)) {
                    $i = $closers[$i] ?? $close;
                }
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
     * is read. With $arguments, each pattern argument follows the patterns
     * it holds, with what is known of it.
     *
     * @param list<PatternOccurrence|PendingPattern<array{int, int}>> $found            a pattern argument is its token
     *                                                                                  range
     * @param list<int>                                               $patternArguments where the pattern arguments start
     * @param array<int, array{int, string, int}|string>              $tokens
     * @param array<int, int>                                         $tokenOffsets
     * @param array<int, int>                                         $closers
     *
     * @return list<PatternOccurrence>
     */
    private function settle(array $found, array $patternArguments, array $tokens, array $tokenOffsets, array $closers, string $file, string $content, bool $arguments): array
    {
        $values = [];
        $resolver = null;
        $assigned = null;
        $pending = $this->pendingRanges($found, $tokens, $closers, $assigned);
        if ([] !== $pending) {
            [$variables, $properties] = $this->watchedNames($pending, $tokens, $closers, $assigned);
            $collector = new TokenValueCollector($tokens, $tokenOffsets, $closers, $content, $variables, $properties);
            $collector->values()->useFileLevel(new FileLevelCode($content, $tokens, $tokenOffsets));
            if (null !== $this->project) {
                $collector->values()->useProject($this->project);
            }
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
        }

        $occurrences = [];
        foreach ($found as $item) {
            if ($item instanceof PatternOccurrence) {
                $occurrences[] = $item;

                continue;
            }

            [$start, $end] = $item->place;
            // None for an argument whose state the call itself gave, or
            // for a variable the file never assigns.
            $value = null === $resolver || null !== $item->state ? null : $values[$start.':'.$end] ?? null;
            $place = self::argumentPlace($tokens, $tokenOffsets, $closers, $start, $end);
            if (null !== $resolver && null !== $value) {
                array_push($occurrences, ...$item->occurrences($resolver, $value, $file, $content, self::span($tokens, $tokenOffsets, $start, $end), $place[1] ?? null));
            }

            if (!$arguments) {
                continue;
            }

            if (null !== $place) {
                $states = null === $resolver || null === $value ? [$item->state ?? ArgumentState::Opaque->value] : $resolver->states($value, $item->function);
                array_push($occurrences, ...$item->arguments($states, $file, $content, $place[0], $place[1], $place[2]));
            }
        }

        return $occurrences;
    }

    /**
     * The token ranges of the held arguments a second pass reads, start =>
     * end => true: one whose state the call itself gave needs none, and a
     * variable gets a value from an assignment, or none, so one the file
     * never assigns is left out.
     *
     * @param list<PatternOccurrence|PendingPattern<array{int, int}>>  $found
     * @param array<int, array{int, string, int}|string>               $tokens
     * @param array<int, int>                                          $closers
     * @param array<string, non-empty-list<array{int, int|null}>>|null $assigned the variables the file assigns, read
     *                                                                           when first asked for
     *
     * @return array<int, non-empty-array<int, true>>
     */
    private function pendingRanges(array $found, array $tokens, array $closers, ?array &$assigned): array
    {
        $pending = [];
        foreach ($found as $item) {
            if (!$item instanceof PendingPattern || null !== $item->state) {
                continue;
            }

            [$start, $end] = $item->place;
            $variable = $this->soleVariable($tokens, $start, $end);
            if (null !== $variable) {
                $assigned ??= $this->assignedVariables($tokens, $closers);
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
     * followed. A variable named in the value assigned to one of them, or
     * in what a foreach whose target is one of them iterates, is one of
     * them, and so on until none is added: a pattern is read through any
     * number of variables.
     *
     * @param array<int, non-empty-array<int, true>>                   $pending
     * @param array<int, array{int, string, int}|string>               $tokens
     * @param array<int, int>                                          $closers
     * @param array<string, non-empty-list<array{int, int|null}>>|null $assigned the variables the file assigns, read
     *                                                                           when first asked for
     *
     * @return array{array<string, true>, bool}
     */
    private function watchedNames(array $pending, array $tokens, array $closers, ?array &$assigned): array
    {
        $variables = [];
        $met = [];
        $properties = false;
        foreach ($pending as $start => $ends) {
            $this->namesIn($tokens, $start, max(array_keys($ends)), $variables, $met, $properties);
        }

        // Each name is followed once, in the order they are met.
        for ($next = 0; isset($met[$next]); $next++) {
            $assigned ??= $this->assignedVariables($tokens, $closers);
            foreach ($assigned[$met[$next]] ?? [] as [$from, $to]) {
                $this->namesIn($tokens, $from, $to ?? self::statementEnd($tokens, $from), $variables, $met, $properties);
            }
        }

        return [$variables, $properties];
    }

    /**
     * Adds the variables the tokens in [$start, $end) name to those met
     * so far, and says whether the tokens name a property.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<string, true>                        $variables
     * @param list<string>                               $met       the same variables, in the order they are met
     */
    private function namesIn(array $tokens, int $start, int $end, array &$variables, array &$met, bool &$properties): void
    {
        for ($i = $start; $i < $end; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            // "${re}" names $re.
            $variable = \T_VARIABLE === $token[0] ? $token[1] : (\T_STRING_VARNAME === $token[0] ? '$'.$token[1] : null);
            if (null !== $variable) {
                if (!isset($variables[$variable])) {
                    $variables[$variable] = true;
                    $met[] = $variable;
                }
            } elseif (!$properties && \T_STRING === $token[0]) {
                $before = PhpTokens::previousSignificant($tokens, $i - 1);
                $properties = null !== $before && PhpTokens::isObjectOrStaticOperator($tokens[$before]);
            }
        }
    }

    /**
     * Where the statement holding the token at $start ends: its ";", the
     * bracket that closes what it stands in, a close tag, or the end of
     * the file. An assigned value runs no further.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function statementEnd(array $tokens, int $start): int
    {
        $depth = 0;
        for ($i = $start, $count = \count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (PhpTokens::opensNesting($token)) {
                $depth++;
            } elseif (')' === $token || ']' === $token || '}' === $token) {
                if (0 === $depth--) {
                    return $i;
                }
            } elseif (0 === $depth && (';' === $token || (\is_array($token) && \T_CLOSE_TAG === $token[0]))) {
                return $i;
            }
        }

        return \count($tokens);
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
            } elseif ((\T_VARIABLE === $id && '$GLOBALS' === $token[1]) || (\T_STRING_VARNAME === $id && 'GLOBALS' === $token[1])) {
                // The variables of the file level, named from anywhere.
                $collector->values()->nameGlobals();
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
        // true or false leaves its flags unknown, and the argument with
        // them.
        if (null !== $patternFunction->utf8Argument) {
            $utf8 = CallArguments::utf8($tokens, $startIndex, $totalTokens, $patternFunction->utf8Argument, $closers);
            if (null === $utf8) {
                $this->holdPattern($tokens, $argument[0], $argument[1], $patternFunction, $sink, ArgumentState::Opaque->value);

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

        $followed = true;
        $range = CallArguments::optionRange($tokens, $argument[0], $argument[1], $patternFunction, $closers, $tokenOffsets, $content, $followed);
        if (null === $range) {
            // Options that hold no pattern give no argument; options the
            // call does not spell out may hold one.
            if (!$followed) {
                $this->holdPattern($tokens, $argument[0], $argument[1], $patternFunction, $sink, ArgumentState::Opaque->value);
            }

            return [];
        }

        // An item of the options written at the call is the pattern
        // itself; options read through a name are looked into for it.
        if ($range !== $argument) {
            $patternFunction = $patternFunction->readingTheItem();
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
        if (null !== ArrayLiteralPatterns::startIndex($stripped)) {
            return $this->extractFromArrayLiteral($stripped, $strippedIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
        }

        // Outside an array literal a keys-function still gets one pattern:
        // Nette's Strings::replace($s, '/re/', 'x') takes a plain string.
        $patternInfo = StringExpressionReader::readPattern($stripped, $strippedIndexes, $tokenOffsets, $content)
            ?? StringExpressionReader::read($stripped, $strippedIndexes, $tokenOffsets, $content);

        if (null === $patternInfo) {
            $this->holdTokens($tokens, $tokenIndexes, $patternFunction, $sink);

            return [];
        }

        // The empty string is an argument, and no pattern.
        $occurrences = [];
        if ('' !== $patternInfo['pattern']) {
            $occurrences[] = $this->createOccurrence($patternInfo, $file, $patternFunction);
            $sink?->append($occurrences[0]);
        }

        $this->holdTokens($tokens, $tokenIndexes, $patternFunction, $sink, ArgumentState::Constant->value);

        return $occurrences;
    }

    /**
     * Hold a pattern argument as the range of its tokens, [$start, $end):
     * what it reads as is known once the whole file is read. One the call
     * site settles is held with its state, a literal as "constant".
     *
     * @param array<int, array{int, string, int}|string>                                $tokens the tokens of the file
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>>|null $sink
     */
    private function holdPattern(array $tokens, int $start, int $end, PatternFunction $patternFunction, ?\ArrayObject $sink, ?string $state = null): void
    {
        // An empty slot is no argument.
        if (null !== $sink && null !== PhpTokens::nextSignificant($tokens, $start, $end)) {
            $sink->append(new PendingPattern([$start, $end], $patternFunction, $state));
        }
    }

    /**
     * Hold a pattern argument given as its tokens and their indexes in the
     * file.
     *
     * @param array<int, array{int, string, int}|string>                                $tokens       the tokens of the argument
     * @param array<int, int>                                                           $tokenIndexes the indexes of its tokens in the file
     * @param \ArrayObject<int, PatternOccurrence|PendingPattern<array{int, int}>>|null $sink
     */
    private function holdTokens(array $tokens, array $tokenIndexes, PatternFunction $patternFunction, ?\ArrayObject $sink, ?string $state = null): void
    {
        if (null !== $sink && [] !== $tokenIndexes && null !== PhpTokens::nextSignificant($tokens, 0, \count($tokens))) {
            $sink->append(new PendingPattern([min($tokenIndexes), max($tokenIndexes) + 1], $patternFunction, $state));
        }
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
        $occurrences = [];
        foreach (ArrayLiteralPatterns::segments($tokens, $tokenIndexes, $tokenOffsets, $content, $patternFunction) as [$segmentTokens, $segmentTokenIndexes]) {
            $this->appendOccurrenceFromSegment($occurrences, $segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content, $file, $patternFunction, $sink);
        }

        return $occurrences;
    }

    /**
     * Read one item of an array literal: each is a pattern argument of its
     * own. A spread may hold any number of patterns, and nothing is known
     * of it; an item taken by reference is read as what it refers to.
     *
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
        $lead = PhpTokens::nextSignificant($tokens, 0, \count($tokens));
        $leadToken = null === $lead ? null : $tokens[$lead];
        if (null !== $lead && \is_array($leadToken) && \in_array($leadToken[0], [\T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
            $tokens = \array_slice($tokens, $lead + 1);
            $tokenIndexes = \array_slice($tokenIndexes, $lead + 1);
        } elseif (\is_array($leadToken) && \T_ELLIPSIS === $leadToken[0]) {
            $this->holdTokens($tokens, $tokenIndexes, $patternFunction, $sink, ArgumentState::Opaque->value);

            return;
        }

        [$stripped, $strippedIndexes] = StringExpressionReader::stripParentheses($tokens, $tokenIndexes);
        $patternInfo = StringExpressionReader::read($stripped, $strippedIndexes, $tokenOffsets, $content);
        if (null === $patternInfo) {
            $this->holdTokens($tokens, $tokenIndexes, $patternFunction, $sink);

            return;
        }

        // The empty string is an argument, and no pattern.
        if ('' !== $patternInfo['pattern']) {
            $occurrence = $this->createOccurrence($patternInfo, $file, $patternFunction);
            $occurrences[] = $occurrence;
            $sink?->append($occurrence);
        }

        $this->holdTokens($tokens, $tokenIndexes, $patternFunction, $sink, ArgumentState::Constant->value);
    }

    /**
     * Where a pattern argument stands, given the range of its tokens,
     * [$start, $end): the line and the byte offset of its first token,
     * the parentheses around the whole of it left out, and its length up
     * to the end of its last token. The whitespace and the comments around
     * it are no part of it. Null for a range that holds no token.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenOffsets
     * @param array<int, int>                            $closers
     *
     * @return array{int, int, int}|null the line, the offset and the length
     */
    private static function argumentPlace(array $tokens, array $tokenOffsets, array $closers, int $start, int $end): ?array
    {
        $first = PhpTokens::nextSignificant($tokens, $start, $end);
        $last = null === $first ? null : PhpTokens::previousSignificant($tokens, $end - 1, $first);
        while (null !== $first && null !== $last && '(' === $tokens[$first] && ($closers[$first] ?? null) === $last) {
            $first = PhpTokens::nextSignificant($tokens, $first + 1, $last);
            $last = null === $first ? null : PhpTokens::previousSignificant($tokens, $last - 1, $first);
        }

        if (null === $first || null === $last) {
            return null;
        }

        $lastToken = $tokens[$last];
        $offset = $tokenOffsets[$first];

        return [PhpTokens::lineOf($tokens, $first), $offset, $tokenOffsets[$last] + \strlen(\is_array($lastToken) ? $lastToken[1] : $lastToken) - $offset];
    }

    /**
     * Where the tokens $start to $end, $end excluded, stand in the content,
     * the whitespace and the comments around them left out: their byte
     * offset and length. A pattern is held only for tokens that name
     * something, so some token holds code.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array{int, int}
     */
    private static function span(array $tokens, array $tokenOffsets, int $start, int $end): array
    {
        $first = $start;
        while (PhpTokens::isIgnorable($tokens[$first])) {
            $first++;
        }

        $last = $end - 1;
        while (PhpTokens::isIgnorable($tokens[$last])) {
            $last--;
        }

        $lastToken = $tokens[$last];
        $offset = $tokenOffsets[$first];

        return [$offset, $tokenOffsets[$last] + \strlen(\is_array($lastToken) ? $lastToken[1] : $lastToken) - $offset];
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

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private function isDefinitionToken(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], [\T_FUNCTION, \T_FN, \T_NEW], true);
    }
}
