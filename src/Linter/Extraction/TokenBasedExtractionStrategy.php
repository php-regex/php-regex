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
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Token-based extraction strategy mirroring PHPStan's preg_* handling.
 *
 * This relies on a small token state machine to track argument positions
 * and only extracts patterns from constant string expressions.
 *
 * @internal
 */
final readonly class TokenBasedExtractionStrategy implements ExtractorInterface
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

    /**
     * Parameter names accepted when a call passes the pattern by name, the
     * same as PhpParserExtractionStrategy's.
     */
    private const PATTERN_PARAMETER_NAMES = [
        'pattern',
        'patterns',
        'regex',
    ];

    private PatternFunctionRegistry $registry;

    /**
     * @param array<int, string> $customFunctions Additional functions/static methods to check (e.g., 'MyClass::customRegexCheck')
     */
    public function __construct(array $customFunctions = [], ?PatternFunctionRegistry $registry = null)
    {
        $this->registry = ($registry ?? PatternFunctionRegistry::defaults())->withCustomFunctions($customFunctions);
    }

    public function extract(array $files): array
    {
        // The functions the files mark with #[RegexPattern] join the
        // registry for this run.
        $specs = PatternAttributeScanner::specs($files);
        $strategy = [] === $specs ? $this : new self([], $this->registry->withCustomFunctions($specs));

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

            $occurrences = [
                ...$occurrences,
                ...$this->extractFromCall(
                    $tokens,
                    $openParenIndex + 1,
                    $totalTokens,
                    $patternFunction,
                    $file,
                    $tokenOffsets,
                    $content,
                ),
            ];
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
        $patternFunction = (null === $namespaced ? null : $this->registry->lookupFunction($namespaced))
            ?? $this->registry->lookupFunction($context->resolveFunction($name));
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
    ): array {
        $argument = $this->findPatternArgument($tokens, $startIndex, $totalTokens, $patternFunction->argumentIndex);
        if (null === $argument) {
            return [];
        }

        return $this->extractFromArgumentTokens($argument[0], $argument[1], $tokenOffsets, $content, $file, $patternFunction);
    }

    /**
     * Locate the pattern argument of a call, passed positionally or by name.
     *
     * A positional argument at the pattern's position wins; otherwise the
     * first argument named like a pattern parameter. A spread before that
     * position makes it unknowable.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{0: array<int, array{int, string, int}|string>, 1: array<int, int>}|null the argument's value tokens and their indexes
     */
    private function findPatternArgument(array $tokens, int $startIndex, int $totalTokens, int $targetArgIndex): ?array
    {
        $position = 0;
        $named = null;
        $depth = 0;
        $argTokens = [];
        $argTokenIndexes = [];

        for ($i = $startIndex; $i <= $totalTokens; $i++) {
            // Running out of tokens closes the call: the source is cut short.
            $token = $tokens[$i] ?? ')';
            $closesCall = $i === $totalTokens || (0 === $depth && ')' === $token);

            if ($closesCall || (0 === $depth && ',' === $token)) {
                // Null for the empty slot a trailing comma leaves.
                $argument = $this->readArgument($argTokens, $argTokenIndexes);
                if (null !== $argument && null === $argument['name']) {
                    if ($argument['spread']) {
                        return null;
                    }

                    if ($position === $targetArgIndex) {
                        return [$argument['tokens'], $argument['indexes']];
                    }

                    $position++;
                } elseif (null !== $argument && null === $named && \in_array(strtolower((string) $argument['name']), self::PATTERN_PARAMETER_NAMES, true)) {
                    $named = [$argument['tokens'], $argument['indexes']];
                }

                if ($closesCall) {
                    break;
                }

                $argTokens = [];
                $argTokenIndexes = [];

                continue;
            }

            if ($this->opensNesting($token)) {
                $depth++;
            } elseif (')' === $token || ']' === $token || '}' === $token) {
                $depth = max(0, $depth - 1);
            }

            $argTokens[] = $token;
            $argTokenIndexes[] = $i;
        }

        return $named;
    }

    /**
     * Split one argument into its name, if passed by name, and its value.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     *
     * @return array{name: string|null, spread: bool, tokens: array<int, array{int, string, int}|string>, indexes: array<int, int>}|null null for an empty slot
     */
    private function readArgument(array $tokens, array $tokenIndexes): ?array
    {
        $count = \count($tokens);
        $first = $this->nextSignificantTokenIndex($tokens, 0, $count);
        if (null === $first) {
            return null;
        }

        $token = $tokens[$first];
        if (\is_array($token) && \T_ELLIPSIS === $token[0]) {
            return ['name' => null, 'spread' => true, 'tokens' => $tokens, 'indexes' => $tokenIndexes];
        }

        // "pattern: '/re/'": an identifier, any reserved word included,
        // followed by a single colon.
        $name = $this->readIdentifierToken($token);
        $colon = $this->nextSignificantTokenIndex($tokens, $first + 1, $count);
        if (null !== $name && null !== $colon && ':' === $tokens[$colon]) {
            return [
                'name' => $name,
                'spread' => false,
                'tokens' => \array_slice($tokens, $colon + 1),
                'indexes' => \array_slice($tokenIndexes, $colon + 1),
            ];
        }

        return ['name' => null, 'spread' => false, 'tokens' => $tokens, 'indexes' => $tokenIndexes];
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
        $patternInfo = $this->parseRegexExpression($tokens, $tokenIndexes, $tokenOffsets, $content)
            ?? $this->parseConstantStringExpression($tokens, $tokenIndexes, $tokenOffsets, $content);

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

        $useKeys = $patternFunction->keysArePatterns;
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

            if ('(' === $token || '[' === $token || '{' === $token) {
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

                $collecting = true;
                $segmentTokens = [];
                $segmentTokenIndexes = [];

                continue;
            }

            if ($atTopLevel && $this->isDoubleArrowToken($token)) {
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
        if (LibraryPcre::match('/^([\'"{}\/#~%])(.*?)([\'"{}\/#~%])([A-Za-z]*)$/', $pattern, $matches)) {
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

                $decoded = $this->decodeHeredoc($heredoc[0], $heredoc[1], $token[1]);
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

    private function decodeStringToken(string $token): string
    {
        if (\strlen($token) < 2) {
            return '';
        }

        $quote = $token[0];
        $body = substr($token, 1, -1);

        if ("'" === $quote) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        if ('"' === $quote) {
            return $this->decodeDoubleQuotedString($body);
        }

        return $body;
    }

    /**
     * Decode a heredoc or nowdoc body as PHP does: drop the newline before
     * the closing marker, remove the marker's indentation from every line,
     * then, for a heredoc, apply the double-quoted escapes but \".
     *
     * Null when PHP refuses the body (a line indented less than the marker,
     * tabs and spaces mixed).
     */
    private function decodeHeredoc(string $start, string $body, string $end): ?string
    {
        $indentation = \strlen($end) - \strlen(ltrim($end, " \t"));
        $indent = substr($end, 0, $indentation);
        if (str_contains($indent, ' ') && str_contains($indent, "\t")) {
            return null;
        }

        if (str_ends_with($body, "\r\n")) {
            $body = substr($body, 0, -2);
        } elseif (str_ends_with($body, "\n") || str_ends_with($body, "\r")) {
            $body = substr($body, 0, -1);
        }

        if ($indentation > 0) {
            $body = $this->removeHeredocIndentation($body, $indentation, $indent[0]);
            if (null === $body) {
                return null;
            }
        }

        // The quote sits around the label of a nowdoc only: <<<'RE'.
        if (str_contains($start, "'")) {
            return $body;
        }

        return $this->decodeDoubleQuotedString($body, '');
    }

    /**
     * @param string $char the indentation character, a space or a tab
     */
    private function removeHeredocIndentation(string $body, int $indentation, string $char): ?string
    {
        $result = '';
        $length = \strlen($body);
        $i = 0;

        while ($i <= $length) {
            // A line ends at \n, \r or \r\n; the last one at the end of the body.
            $lineEnd = $i;
            while ($lineEnd < $length && "\n" !== $body[$lineEnd] && "\r" !== $body[$lineEnd]) {
                $lineEnd++;
            }

            $newline = 0;
            if ($lineEnd < $length) {
                $newline = "\r" === $body[$lineEnd] && "\n" === ($body[$lineEnd + 1] ?? '') ? 2 : 1;
            }

            for ($skip = 0; $skip < $indentation && $i < $lineEnd; $skip++, $i++) {
                // A whitespace-only line may be indented less; any other may not.
                if (' ' !== $body[$i] && "\t" !== $body[$i]) {
                    return null;
                }

                if ($char !== $body[$i]) {
                    return null;
                }
            }

            $result .= substr($body, $i, $lineEnd - $i + $newline);
            if (0 === $newline) {
                break;
            }

            $i = $lineEnd + $newline;
        }

        return $result;
    }

    /**
     * @param string $quote the delimiter its backslash escapes: '"' in a
     *                      double-quoted string, none in a heredoc
     */
    private function decodeDoubleQuotedString(string $body, string $quote = '"'): string
    {
        $result = '';
        $length = \strlen($body);
        $i = 0;

        while ($i < $length) {
            $char = $body[$i];

            if ('\\' !== $char) {
                $result .= $char;
                $i++;

                continue;
            }

            if ($i + 1 >= $length) {
                $result .= $char;
                $i++;

                continue;
            }

            $nextChar = $body[$i + 1];

            switch ($nextChar) {
                case 'n':
                    $result .= "\n";
                    $i += 2;

                    break;
                case 'r':
                    $result .= "\r";
                    $i += 2;

                    break;
                case 't':
                    $result .= "\t";
                    $i += 2;

                    break;
                case 'v':
                    $result .= "\v";
                    $i += 2;

                    break;
                case 'e':
                    $result .= "\e";
                    $i += 2;

                    break;
                case 'f':
                    $result .= "\f";
                    $i += 2;

                    break;
                case '\\':
                    $result .= '\\';
                    $i += 2;

                    break;
                case '$':
                    $result .= '$';
                    $i += 2;

                    break;
                case $quote:
                    $result .= $quote;
                    $i += 2;

                    break;
                case 'x':
                    $hexResult = $this->parseHexEscape($body, $i, $length);
                    $result .= $hexResult['value'];
                    $i = $hexResult['newIndex'];

                    break;
                case 'u':
                    $unicodeResult = $this->parseUnicodeEscape($body, $i, $length);
                    $result .= $unicodeResult['value'];
                    $i = $unicodeResult['newIndex'];

                    break;
                case '0':
                case '1':
                case '2':
                case '3':
                case '4':
                case '5':
                case '6':
                case '7':
                    $octalResult = $this->parseOctalEscape($body, $i, $length);
                    $result .= $octalResult['value'];
                    $i = $octalResult['newIndex'];

                    break;
                default:
                    $result .= '\\'.$nextChar;
                    $i += 2;

                    break;
            }
        }

        return $result;
    }

    /**
     * @return array{value: string, newIndex: int}
     */
    private function parseHexEscape(string $body, int $i, int $length): array
    {
        $startPos = $i + 2;

        if ($startPos >= $length) {
            return ['value' => '\\x', 'newIndex' => $startPos];
        }

        if ('{' === $body[$startPos]) {
            $closeBrace = strpos($body, '}', $startPos);
            if (false !== $closeBrace) {
                $sequence = substr($body, $i, $closeBrace - $i + 1);

                return ['value' => $sequence, 'newIndex' => $closeBrace + 1];
            }

            return ['value' => '\\x{', 'newIndex' => $startPos + 1];
        }

        $hexDigits = '';
        $pos = $startPos;
        while ($pos < $length && $pos < $startPos + 2 && Ascii::isHexDigit($body[$pos])) {
            $hexDigits .= $body[$pos];
            $pos++;
        }

        if ('' === $hexDigits) {
            return ['value' => '\\x', 'newIndex' => $startPos];
        }

        $charCode = (int) hexdec($hexDigits);

        return ['value' => \chr($charCode & 0xFF), 'newIndex' => $pos];
    }

    /**
     * @return array{value: string, newIndex: int}
     */
    private function parseUnicodeEscape(string $body, int $i, int $length): array
    {
        $startPos = $i + 2;

        if ($startPos >= $length || '{' !== $body[$startPos]) {
            return ['value' => '\\u', 'newIndex' => $startPos];
        }

        $closeBrace = strpos($body, '}', $startPos);
        if (false === $closeBrace) {
            return ['value' => '\\u{', 'newIndex' => $startPos + 1];
        }

        $hexPart = substr($body, $startPos + 1, $closeBrace - $startPos - 1);

        if ('' === $hexPart || !Ascii::isHexDigit($hexPart)) {
            return ['value' => substr($body, $i, $closeBrace - $i + 1), 'newIndex' => $closeBrace + 1];
        }

        $codepoint = (int) hexdec($hexPart);

        return ['value' => $this->codepointToUtf8($codepoint), 'newIndex' => $closeBrace + 1];
    }

    /**
     * @return array{value: string, newIndex: int}
     */
    private function parseOctalEscape(string $body, int $i, int $length): array
    {
        $startPos = $i + 1;
        $octalDigits = '';
        $pos = $startPos;

        while ($pos < $length && $pos < $startPos + 3 && $body[$pos] >= '0' && $body[$pos] <= '7') {
            $octalDigits .= $body[$pos];
            $pos++;
        }

        if ('' === $octalDigits) {
            return ['value' => '\\', 'newIndex' => $startPos];
        }

        $charCode = (int) octdec($octalDigits);

        return ['value' => \chr($charCode & 0xFF), 'newIndex' => $pos];
    }

    private function codepointToUtf8(int $codepoint): string
    {
        if ($codepoint < 0x80) {
            return \chr($codepoint & 0x7F);
        }
        if ($codepoint < 0x800) {
            return \chr((0xC0 | ($codepoint >> 6)) & 0xFF).\chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint < 0x10000) {
            return \chr((0xE0 | ($codepoint >> 12)) & 0xFF).\chr(0x80 | (($codepoint >> 6) & 0x3F)).\chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr((0xF0 | ($codepoint >> 18)) & 0xFF).\chr(0x80 | (($codepoint >> 12) & 0x3F)).\chr(0x80 | (($codepoint >> 6) & 0x3F)).\chr(0x80 | ($codepoint & 0x3F));
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
