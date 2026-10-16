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
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Validation\RangeDiff;
use PHPRegex\Rector\Internal\TargetRange;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;

/**
 * Writes a NUL byte of a pattern as the escape "\x00", for code that runs
 * on a PHP older than 8.2: PHP 7.3 to 8.1 refuse a pattern holding the
 * byte ("Null byte in regex"), where PHP 8.2 reads it. On PHP 8.1,
 * preg_match("/a\0b/", $s) fails; the call becomes
 * preg_match('/a\x00b/', $s), which every PHP reads as PHP 8.2 reads the
 * byte.
 *
 * The range runs from the PHP the code targets, as Rector reads it, to the
 * newest PHP the library knows: the call is rewritten only when the
 * targeted PHP refuses the pattern, and once the pattern, rewritten, reads
 * alike on every PHP of the range and as the newest read the original. A
 * byte the pattern does not read as a character of its own, as in a
 * comment or after a backslash, is left as it is, and so is a pattern with
 * "\Q" or written in more than one string literal.
 */
final class EscapeNulByteRector extends AbstractRector
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
        $escaped = null === $literal || !str_contains($literal->value, "\0") || str_contains($literal->value, '\Q')
            ? null
            : self::escaped($literal->value, TargetRange::from($this->phpVersionProvider->provide()));

        return null === $escaped ? null : TargetRange::withPattern($node, $escaped);
    }

    /**
     * The pattern with each NUL byte read as a character written "\x00";
     * null when the targeted PHP reads the pattern, or the rewritten
     * pattern would not read alike everywhere.
     */
    private static function escaped(string $pattern, RangeDiff $range): ?string
    {
        $reader = $range->to();
        if ($range->diff($pattern)->from->validation->isValid || !$reader->validate($pattern)->isValid) {
            return null;
        }

        try {
            // Positions count from the start of the body, after the
            // one-byte delimiter.
            $tree = $reader->parse($pattern);
            $source = $tree->source ?? '';
            $bytes = self::nulBytes($tree->pattern, $source);
            rsort($bytes);
            $body = $source;
            foreach ($bytes as $offset) {
                $body = substr($body, 0, $offset).'\x00'.substr($body, $offset + 1);
            }
            $rewritten = $pattern[0].$body.substr($pattern, 1 + \strlen($source));

            return TargetRange::keepsTheReading($range, $reader, $pattern, $rewritten) ? $rewritten : null;
        } catch (RegexException) {
            return null; // never taken: the newest PHP validated the pattern before it is parsed
        }
    }

    /**
     * The offsets of the NUL bytes the tree reads as a character written
     * as the byte itself, inside a class or out.
     *
     * @return list<int>
     */
    private static function nulBytes(NodeInterface $node, string $source): array
    {
        $start = $node->getStartPosition();
        if ($node instanceof LiteralNode && "\0" === $node->value && 1 === $node->getEndPosition() - $start && "\0" === ($source[$start] ?? '')) {
            return [$start];
        }

        $offsets = [];
        foreach ($node->getChildren() as $child) {
            array_push($offsets, ...self::nulBytes($child, $source));
        }

        return $offsets;
    }
}
