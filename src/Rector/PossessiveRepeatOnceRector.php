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
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\Validation\RangeDiff;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;
use Rector\Reflection\ReflectionResolver;

/**
 * Drops the "+" of a possessive "{1}+" on a group, for code leaving a PHP
 * whose PCRE2 ignores it: PCRE2 10.33 and earlier (PHP 7.3, and 7.4.0 to
 * 7.4.5) ignore a "{1}" possessive or not, and 10.34 makes the group atomic.
 * On PHP 7.4.0, preg_match('/(a|ab){1}+c/', 'abc') finds "abc", and from
 * 7.4.6 nothing; the call becomes preg_match('/(a|ab){1}c/', $s), which
 * every PHP reads as 7.4.0 read the original. The tree is the same on both
 * sides of the change, so no comparison of trees sees it: the release
 * table says where it lies.
 *
 * The range runs from the PHP the code targets, as Rector reads it, to the
 * newest PHP the library knows: the call is rewritten only when the targeted
 * PHP ignores the repeat and a later one honours it, and once the pattern,
 * rewritten, reads alike on every PHP of the range. A repeat on a single
 * character, an atomic group or a lookaround means the same either way, and
 * is left as written.
 */
final class PossessiveRepeatOnceRector extends AbstractRector
{
    /**
     * PHP 7.4.6 moved to PCRE2 10.34, which honours the repeat: from this
     * patch on the targeted PHP already reads it as later ones do.
     */
    private const PHP_HONOURING_THE_REPEAT = 70406;

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
        $phpVersion = $this->phpVersionProvider->provide();
        if ($phpVersion >= self::PHP_HONOURING_THE_REPEAT) {
            return null;
        }

        $range = TargetRange::from($phpVersion);

        $rewrite = static fn (string $pattern): ?string => str_contains($pattern, '}+') ? self::rewritten($pattern, $range) : null;
        $pregCall = fn (CallLike $call): bool => $call instanceof FuncCall && $this->isNames($call, TargetRange::FUNCTIONS);
        $callbackArray = fn (CallLike $call): bool => $call instanceof FuncCall && $this->isName($call, 'preg_replace_callback_array');

        if ($node instanceof Class_) {
            return PrivateConstants::rewrite($node, $pregCall, $callbackArray, $this->reflectionResolver, $rewrite);
        }

        return TargetRange::rewriteEach($node, $pregCall($node), $callbackArray($node), $this->reflectionResolver, $rewrite);
    }

    /**
     * The pattern with the "+" of each possessive "{1}+" on a group dropped;
     * null when the pattern holds none, or when the rewritten pattern would
     * not read alike on every PHP of the range. The range runs to the newest
     * PHP, which honours the repeat (PcreFeature::PossessiveRepeatOnceIsAtomic).
     */
    private static function rewritten(string $pattern, RangeDiff $range): ?string
    {
        $reader = $range->from();
        $verdict = $range->diff($pattern);
        if (!$verdict->from->validation->isValid || null !== $verdict->refusedBy()) {
            return null;
        }

        try {
            // Positions count from the start of the body, after the
            // one-byte delimiter.
            $tree = $reader->parse($pattern);
            $source = $tree->source ?? '';
            $pluses = self::pluses($tree->pattern, $source);
            if ([] === $pluses) {
                return null;
            }

            rsort($pluses);
            $body = $source;
            foreach ($pluses as $offset) {
                $body = substr($body, 0, $offset).substr($body, $offset + 1);
            }
            $rewritten = $pattern[0].$body.substr($pattern, 1 + \strlen($source));

            return self::keepsTheTree($range, $pattern, $rewritten, \count($pluses)) ? $rewritten : null;
        } catch (RegexException) {
            return null; // never taken: the targeted PHP validated the pattern before it is parsed
        }
    }

    /**
     * The offsets of the "+" that makes each "{1}" on a group possessive.
     *
     * @return list<int>
     */
    private static function pluses(NodeInterface $node, string $source): array
    {
        $pluses = [];
        if ($node instanceof QuantifierNode && QuantifierType::Possessive === $node->type && self::flips($node->node)) {
            $bounds = QuantifierBounds::parse($node->quantifier);
            $plus = $node->getEndPosition() - 1;
            if (null !== $bounds && 1 === $bounds->min && 1 === $bounds->max && '+' === ($source[$plus] ?? '')) {
                $pluses[] = $plus;
            }
        }

        foreach ($node->getChildren() as $child) {
            $pluses = [...$pluses, ...self::pluses($child, $source)];
        }

        return $pluses;
    }

    /**
     * Whether the item the repeat stands on holds a backtracking point the
     * atomic reading gives up: a group but an atomic one or a lookaround, a
     * script run but an atomic one, or a subroutine call. pcre2test 10.33 and 10.34 tell each
     * apart on "(…|…){1}+".
     */
    private static function flips(NodeInterface $item): bool
    {
        if ($item instanceof ScriptRunNode) {
            return !$item->isAtomic;
        }

        if ($item instanceof SubroutineNode) {
            return true;
        }

        return $item instanceof GroupNode && !\in_array($item->type, [
            GroupType::Atomic,
            GroupType::LookaheadPositive,
            GroupType::LookaheadNegative,
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
        ], true);
    }

    /**
     * Whether the rewritten pattern reads alike on every PHP of the range,
     * and the tree the targeted PHP reads differs from the original's in
     * the type of $dropped repeats only.
     */
    private static function keepsTheTree(RangeDiff $range, string $pattern, string $rewritten, int $dropped): bool
    {
        $verdict = $range->diff($rewritten);
        if (!$verdict->from->validation->isValid || null !== $verdict->refusedBy() || null !== $verdict->readsOtherwise) {
            return false;
        }

        $before = $range->from()->parse($pattern)->accept(new NodeDumper());
        $after = $range->from()->parse($rewritten)->accept(new NodeDumper());
        $anyType = static fn (string $dump): string => str_replace(['type: possessive)', 'type: greedy)'], 'type: *)', $dump);

        return $anyType($before) === $anyType($after)
            && substr_count($before, 'type: possessive)') - substr_count($after, 'type: possessive)') === $dropped;
    }
}
