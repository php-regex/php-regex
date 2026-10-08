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

namespace PHPRegex\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Rector\Internal\PregCall;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;
use Rector\ValueObject\PhpVersion;

/**
 * Escapes a brace PCRE2 before 10.43 reads as text and PCRE2 10.43 (PHP
 * 8.4) as the start of a quantifier: on PHP 8.2, preg_match('/a{,3}/', $s)
 * finds the text "a{,3}"; on PHP 8.4 it finds "a" up to three times. The
 * call becomes preg_match('/a\{,3}/', $s), which finds the text on both.
 *
 * Only for code that targets a PHP below 8.4, a pattern written as one
 * string literal, and once the pattern, rewritten, reads alike on the
 * project's PHP and on 8.4. A pattern with "\Q", a "{" delimiter, or one
 * either PHP refuses is left as it is.
 */
final class EscapeLiteralBraceRector extends AbstractRector
{
    private const FUNCTIONS = ['preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split', 'preg_grep', 'preg_filter'];

    public function __construct(private readonly PhpVersionProvider $phpVersionProvider) {}

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FuncCall::class];
    }

    /**
     * @param FuncCall $node
     */
    public function refactor(Node $node): ?Node
    {
        $phpVersion = $this->phpVersionProvider->provide();
        if ($phpVersion >= PhpVersion::PHP_84 || !$this->isNames($node, self::FUNCTIONS) || $node->isFirstClassCallable()) {
            return null;
        }

        $argument = $node->getArgs()[0] ?? null;
        if (null === $argument || !$argument->value instanceof String_ || null !== $argument->name || $argument->unpack) {
            return null;
        }

        $escaped = $this->escaped($argument->value->value, PregCall::targetParser($phpVersion), PregCall::targetParser(PhpVersion::PHP_84));
        if (null === $escaped) {
            return null;
        }

        $argument->value = PregCall::string($escaped);

        return $node;
    }

    /**
     * The pattern with a backslash before each brace read as text that a
     * later release would read otherwise; null when nothing differs, when
     * the pattern is not one this rewrite can judge, or when the rewritten
     * pattern would still read otherwise.
     */
    private function escaped(string $pattern, RegexParser $current, RegexParser $later): ?string
    {
        if ('' === $pattern || '{' === $pattern[0] || str_contains($pattern, '\Q')
            || !PregCall::targetAccepts($pattern, $current) || !PregCall::targetAccepts($pattern, $later)) {
            return null;
        }

        try {
            $tree = $current->parse($pattern);
            if ($tree->accept(new NodeDumper()) === $later->parse($pattern)->accept(new NodeDumper())) {
                return null;
            }

            // Positions count from the start of the body, after the
            // one-byte delimiter.
            $source = $tree->source ?? '';
            $braces = self::literalBraces($tree->pattern, $source);
            rsort($braces);
            $body = $source;
            foreach ($braces as $offset) {
                $body = substr($body, 0, $offset).'\\'.substr($body, $offset);
            }
            $rewritten = $pattern[0].$body.substr($pattern, 1 + \strlen($source));

            return PregCall::targetAccepts($rewritten, $current)
                && $current->parse($rewritten)->accept(new NodeDumper()) === $later->parse($rewritten)->accept(new NodeDumper())
                ? $rewritten
                : null;
        } catch (RegexException) {
            return null; // never taken: both PHPs validated the pattern before it is parsed
        }
    }

    /**
     * The offsets of the "{" written bare, outside a class, that the tree
     * reads as text.
     *
     * @return list<int>
     */
    private static function literalBraces(NodeInterface $node, string $source): array
    {
        if ($node instanceof CharClassNode) {
            return [];
        }

        $start = $node->getStartPosition();
        if ($node instanceof LiteralNode && '{' === $node->value && 1 === $node->getEndPosition() - $start && '{' === ($source[$start] ?? '')) {
            return [$start];
        }

        $offsets = [];
        foreach ($node->getChildren() as $child) {
            array_push($offsets, ...self::literalBraces($child, $source));
        }

        return $offsets;
    }
}
