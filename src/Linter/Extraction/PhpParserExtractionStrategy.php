<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Extraction;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpRegex\Linter\PatternOccurrence;

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
final readonly class PhpParserExtractionStrategy implements ExtractorInterface
{
    /**
     * Parameter names accepted when a call passes the pattern by name.
     */
    private const PATTERN_PARAMETER_NAMES = [
        'pattern',
        'patterns',
        'regex',
    ];

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

    public function extract(array $files): array
    {
        if (empty($files)) {
            return [];
        }

        return $this->analyzeFilesWithPhpStan($files);
    }

    /**
     * @param array<string> $files
     *
     * @return array<\PhpRegex\Linter\PatternOccurrence>
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
     * @return array<\PhpRegex\Linter\PatternOccurrence>
     */
    private function analyzeFileWithPhpStan(string $file): array
    {
        try {
            if (null === $this->parser) {
                return [];
            }

            if (!is_file($file) || !is_readable($file)) {
                return [];
            }

            $content = file_get_contents($file);
            if (false === $content || '' === $content) {
                return [];
            }

            if (!$this->registry->matchesContent($content)) {
                return [];
            }

            if (!MemoryBudget::allows($content, MemoryBudget::PARSE_FACTOR)) {
                return [];
            }

            $ast = $this->parser->parse($content);
            if (!\is_array($ast)) {
                return [];
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);

            return $this->extractFromTokens($ast, $file, $content);
        } catch (\Throwable) {
            // If analysis fails for this file, return empty results
            return [];
        }
    }

    /**
     * @param array<Node> $tokens
     *
     * @return array<\PhpRegex\Linter\PatternOccurrence>
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
     * @return array<\PhpRegex\Linter\PatternOccurrence>
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
     * @return array<\PhpRegex\Linter\PatternOccurrence>
     */
    private function extractFromFuncCall(FuncCall $funcCall, string $file, string $content): array
    {
        if (!$funcCall->name instanceof Name) {
            return [];
        }

        $patternFunction = $this->registry->lookupFunction($funcCall->name->toString());
        if (null === $patternFunction) {
            return [];
        }

        return $this->extractFromArgs($funcCall->getArgs(), $patternFunction, $file, $content);
    }

    /**
     * @return array<\PhpRegex\Linter\PatternOccurrence>
     */
    private function extractFromStaticCall(StaticCall $staticCall, string $file, string $content): array
    {
        if (!$staticCall->class instanceof Name || !$staticCall->name instanceof Identifier) {
            return [];
        }

        $patternFunction = $this->registry->lookupMethod($staticCall->class->toString(), $staticCall->name->toString());
        if (null === $patternFunction) {
            return [];
        }

        return $this->extractFromArgs($staticCall->getArgs(), $patternFunction, $file, $content);
    }

    /**
     * @param array<Arg> $args
     *
     * @return array<\PhpRegex\Linter\PatternOccurrence>
     */
    private function extractFromArgs(array $args, PatternFunction $patternFunction, string $file, string $content): array
    {
        $arg = $this->findPatternArg($args, $patternFunction->argumentIndex);
        if (null === $arg) {
            return [];
        }

        return $this->extractPatternFromArg($arg, $patternFunction, $file, $content);
    }

    /**
     * Locate the pattern argument, whether it was passed positionally or by name.
     *
     * @param array<Arg> $args
     */
    private function findPatternArg(array $args, int $argumentIndex): ?Arg
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

            if (\in_array(strtolower($arg->name->toString()), self::PATTERN_PARAMETER_NAMES, true)) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * @return array<\PhpRegex\Linter\PatternOccurrence>
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
     * @return array<\PhpRegex\Linter\PatternOccurrence>
     */
    private function extractPatternsFromArray(Array_ $array, PatternFunction $patternFunction, string $file, string $content): array
    {
        $occurrences = [];

        foreach ($array->items as $item) {
            if (null === $item) {
                continue;
            }

            $expr = $patternFunction->keysArePatterns ? $item->key : $item->value;
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
     * @param array<\PhpRegex\Linter\PatternOccurrence> $occurrences
     * @param array<\PhpRegex\Linter\PatternOccurrence> $items
     */
    private function appendOccurrences(array &$occurrences, array $items): void
    {
        foreach ($items as $item) {
            $occurrences[] = $item;
        }
    }
}
