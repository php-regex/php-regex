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
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Validation\RangeDiff;
use PHPRegex\Rector\Internal\PrivateConstants;
use PHPRegex\Rector\Internal\TargetRange;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;
use Rector\Reflection\ReflectionResolver;

/**
 * Spells an assertion or an atomic group with the opener every PCRE2
 * release reads, for code that runs on a PHP older than the alphabetic
 * names: PCRE2 10.33 (PHP 7.4) added "(*pla:", "(*positive_lookahead:" and
 * the others, which PHP 7.3's PCRE2 10.32 refuses. On PHP 7.3,
 * preg_match('/(*pla:foo)\w+/', $s) fails to compile; the call becomes
 * preg_match('/(?=foo)\w+/', $s), which every PHP reads as later ones read
 * the alphabetic name.
 *
 * The range runs from the PHP the code targets, as Rector reads it, to the
 * newest PHP the library knows: the call is rewritten only when the
 * targeted PHP refuses the pattern, and once the pattern, rewritten, reads
 * alike on every PHP of the range and as the newest read the original. The
 * non-atomic assertions, "(*napla:" and "(?*", and the script runs have no
 * spelling older releases read: they are left as they are, and so is a
 * pattern written in more than one string literal.
 */
final class AlphabeticAssertionToClassicRector extends AbstractRector
{
    /**
     * The alphabetic names PCRE2 10.33 added, with the opener that means the
     * same in every release.
     */
    private const OPENERS = [
        'pla' => '(?=',
        'positive_lookahead' => '(?=',
        'nla' => '(?!',
        'negative_lookahead' => '(?!',
        'plb' => '(?<=',
        'positive_lookbehind' => '(?<=',
        'nlb' => '(?<!',
        'negative_lookbehind' => '(?<!',
        'atomic' => '(?>',
    ];

    public function __construct(private readonly PhpVersionProvider $phpVersionProvider, private readonly ReflectionResolver $reflectionResolver) {}

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FuncCall::class, StaticCall::class, Class_::class];
    }

    /**
     * @param FuncCall|StaticCall|Class_ $node
     *
     * @return FuncCall|StaticCall|Class_|null the same call, its pattern literals rewritten, or the same class, the private constants it reads as patterns only rewritten
     */
    public function refactor(Node $node): ?Node
    {
        $range = TargetRange::from($this->phpVersionProvider->provide());

        $rewrite = static fn (string $pattern): ?string => str_contains($pattern, '(*') ? self::respelled($pattern, $range) : null;
        $pregCall = fn (CallLike $call): bool => $call instanceof FuncCall && $this->isNames($call, TargetRange::FUNCTIONS);
        $callbackArray = fn (CallLike $call): bool => $call instanceof FuncCall && $this->isName($call, 'preg_replace_callback_array');

        if ($node instanceof Class_) {
            return PrivateConstants::rewrite($node, $pregCall, $callbackArray, $this->reflectionResolver, $rewrite);
        }

        return TargetRange::rewriteEach($node, $pregCall($node), $callbackArray($node), $this->reflectionResolver, $rewrite);
    }

    /**
     * The pattern with each alphabetic opener the newest PHP reads spelled
     * the classic way; null when the targeted PHP reads the pattern, or the
     * rewritten pattern would not read alike everywhere.
     */
    private static function respelled(string $pattern, RangeDiff $range): ?string
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
            $openers = self::openers($tree->pattern, $source);
            krsort($openers);
            $body = $source;
            foreach ($openers as $offset => [$length, $classic]) {
                $body = substr($body, 0, $offset).$classic.substr($body, $offset + $length);
            }
            $rewritten = $pattern[0].$body.substr($pattern, 1 + \strlen($source));

            return TargetRange::keepsTheReading($range, $reader, $pattern, $rewritten) ? $rewritten : null;
        } catch (RegexException) {
            return null; // never taken: the newest PHP validated the pattern before it is parsed
        }
    }

    /**
     * The alphabetic openers of the groups of the tree, "(*pla:" and the
     * like, by offset: their length and the classic opener.
     *
     * @return array<int, array{int, string}>
     */
    private static function openers(NodeInterface $node, string $source): array
    {
        $openers = [];
        if ($node instanceof GroupNode) {
            $start = $node->getStartPosition();
            $nameLength = strspn($source, 'abcdefghijklmnopqrstuvwxyz_', $start + 2);
            $name = substr($source, $start + 2, $nameLength);
            if ('(*' === substr($source, $start, 2) && ':' === ($source[$start + 2 + $nameLength] ?? '') && isset(self::OPENERS[$name])) {
                $openers[$start] = [3 + $nameLength, self::OPENERS[$name]];
            }
        }

        foreach ($node->getChildren() as $child) {
            $openers += self::openers($child, $source);
        }

        return $openers;
    }
}
