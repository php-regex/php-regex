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
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Validation\RangeDiff;
use PHPRegex\Rector\Internal\TargetRange;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;

/**
 * Escapes a brace PCRE2 before 10.43 reads as text and PCRE2 10.43 (PHP
 * 8.4) as the start of a quantifier: on PHP 8.2, preg_match('/a{,3}/', $s)
 * finds the text "a{,3}"; on PHP 8.4 it finds "a" up to three times. The
 * call becomes preg_match('/a\{,3}/', $s), which finds the text on both.
 *
 * The range runs from the PHP the code targets, as Rector reads it
 * (withPhpVersion(), else composer.json), to the newest PHP the library
 * knows, through every PHP version a verdict may change at: nothing to
 * configure. The call is rewritten only when a PHP of that range reads the
 * pattern otherwise, and once the pattern, rewritten, reads alike on every
 * one of them. A pattern written in more than one string literal, with
 * "\Q" or a "{" delimiter, or one a PHP of the range refuses, is left as it
 * is: code that targets PHP 8.4 or later is never rewritten.
 */
final class EscapeLiteralBraceRector extends AbstractRector
{
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
     *
     * @return FuncCall|null the same call, its pattern literal rewritten
     */
    public function refactor(Node $node): ?Node
    {
        $literal = $this->isNames($node, TargetRange::FUNCTIONS) ? TargetRange::patternLiteral($node) : null;
        $escaped = null === $literal ? null : self::escaped($literal->value, TargetRange::from($this->phpVersionProvider->provide()));

        return null === $escaped ? null : TargetRange::withPattern($node, $escaped);
    }

    /**
     * The pattern with a backslash before each brace read as text; null
     * when no PHP of the range reads it otherwise, when the pattern is not
     * one this rewrite can judge, or when the rewritten pattern would not
     * read alike on every PHP of the range.
     *
     * The reading kept is the targeted PHP's, when a later one reads a
     * brace otherwise; the newest PHP's, when the targeted PHP refuses a
     * brace a later one reads as text, as "a{65536" before PCRE2 10.37.
     */
    private static function escaped(string $pattern, RangeDiff $range): ?string
    {
        if ('' === $pattern || '{' === $pattern[0] || str_contains($pattern, '\Q')) {
            return null;
        }

        $verdict = $range->diff($pattern);
        if ($verdict->from->validation->isValid) {
            if (null !== $verdict->refusedBy() || null === $verdict->readsOtherwise) {
                return null;
            }
            $reader = $range->from();
        } else {
            $reader = $range->to();
            if (!$reader->validate($pattern)->isValid) {
                return null;
            }
        }

        try {
            // Positions count from the start of the body, after the
            // one-byte delimiter.
            $tree = $reader->parse($pattern);
            $source = $tree->source ?? '';
            $braces = self::literalBraces($tree->pattern, $source);
            rsort($braces);
            $body = $source;
            foreach ($braces as $offset) {
                $body = substr($body, 0, $offset).'\\'.substr($body, $offset);
            }
            $rewritten = $pattern[0].$body.substr($pattern, 1 + \strlen($source));

            return TargetRange::keepsTheReading($range, $reader, $pattern, $rewritten) ? $rewritten : null;
        } catch (RegexException) {
            return null; // never taken: the reader validated the pattern before it is parsed
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
