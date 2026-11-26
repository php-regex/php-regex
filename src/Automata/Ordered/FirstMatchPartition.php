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

namespace PHPRegex\Automata\Ordered;

use PHPRegex\Automata\Builder\NfaComposer;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Exception\IncompatibleArgumentException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\PackedNfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\LazyDfa;
use PHPRegex\Automata\Solver\ProductSearch;
use PHPRegex\Automata\Unicode\CodePointHelper;

/**
 * The strings each candidate of an ordered list keeps when the first one
 * that matches takes the string, as a router takes a request or a firewall
 * an access rule: Eff(i) = L(i) \ (L(1) ∪ ... ∪ L(i - 1)).
 *
 * A candidate no single earlier one holds may still be held by several
 * together: "/a/[0-9]+" and "/a/[a-z]+" before "/a/[a-z0-9]+" leave it
 * nothing. The union is read over the earlier candidates that share a
 * string with it only, the others taking none of its strings, and the
 * difference walks the candidate's NFA against that union determinized
 * as it is reached.
 *
 * A candidate is the tuples of patterns it takes, one pattern per field:
 * the fields are joined by a separator past Unicode (NfaComposer), never
 * through the text of the patterns, and the tuples united. A candidate
 * given as null may take nothing (a route with a condition, a rule matched
 * on an IP): it shadows nothing and gets no cell.
 */
final class FirstMatchPartition
{
    private const MAX_BYTE = 255;

    /**
     * @var array<string, Nfa>
     */
    private array $nfas = [];

    private readonly LanguageSolver $solver;

    private readonly SolverOptions $options;

    /**
     * @param LanguageSolver|null $solver  reads the patterns, for the PHP and PCRE2 target of its parser; a default solver when null
     * @param SolverOptions|null  $options the limits of the search; the default ones when null
     */
    public function __construct(?LanguageSolver $solver = null, ?SolverOptions $options = null)
    {
        $this->solver = $solver ?? new LanguageSolver();
        $this->options = $options ?? new SolverOptions();
    }

    /**
     * @param list<FirstMatchCandidate|null> $candidates in the order they are tried; null for one that may take nothing
     *
     * @throws IncompatibleArgumentException When the tuples do not all hold the same number of fields
     *
     * @return array<int, FirstMatchCell> by the place of each candidate in the list, null ones left out
     */
    public function partition(array $candidates): array
    {
        $composer = new NfaComposer();
        $arity = null;
        /** @var array<int, array{Nfa|null, array<int, bool>, string|null}> $built */
        $built = [];
        foreach ($candidates as $index => $candidate) {
            if (null !== $candidate) {
                $built[$index] = $this->build($candidate->takes, $composer, $arity);
            }
        }

        $cells = [];
        foreach ($built as $index => $taken) {
            $given = $candidates[$index];
            \assert(null !== $given);
            [$kept, $unicode, $refused] = null === $given->keeps ? $taken : $this->build($given->keeps, $composer, $arity);
            if (null !== $refused) {
                $cells[$index] = new FirstMatchCell($index, null, null, refused: $refused);
            } elseif (null === $kept) {
                $cells[$index] = new FirstMatchCell($index, false, null);
            } else {
                $rivals = array_filter($given->rivals ?? array_keys($candidates), static fn (int $j): bool => $j < $index && null !== ($built[$j][0] ?? null));

                try {
                    $cells[$index] = $this->cell($index, $kept, $unicode, $rivals, $built, $composer);
                } catch (ComplexityException $exception) {
                    $cells[$index] = new FirstMatchCell($index, null, null, refused: $exception->getMessage());
                }
            }
        }

        return $cells;
    }

    /**
     * The NFA of the tuples, the fields joined and the tuples united, with
     * whether each field reads UTF-8, or why the solver gave up on it.
     *
     * @param list<list<string>> $tuples
     *
     * @throws IncompatibleArgumentException
     *
     * @return array{Nfa|null, array<int, bool>, string|null}
     */
    private function build(array $tuples, NfaComposer $composer, ?int &$arity): array
    {
        $nfas = [];
        $unicode = [];
        foreach ($tuples as $tuple) {
            $arity ??= \count($tuple);
            if (\count($tuple) !== $arity || 0 === $arity) {
                throw new IncompatibleArgumentException(\sprintf('Every tuple must hold the same number of fields, at least one: %d expected, %d given.', $arity, \count($tuple)));
            }

            $joined = null;
            foreach (array_values($tuple) as $field => $pattern) {
                try {
                    $nfa = $this->nfa($pattern);
                } catch (ComplexityException $exception) {
                    return [null, [], $exception->getMessage()];
                }

                $unicode[$field] = ($unicode[$field] ?? false) || $nfa->maxCodePoint > self::MAX_BYTE;
                $joined = null === $joined ? $nfa : $composer->concat($joined, $nfa);
            }

            \assert(null !== $joined);
            $nfas[] = $joined;
        }

        return [[] === $nfas ? null : $composer->union(...$nfas), $unicode, null];
    }

    /**
     * @param array<int, bool>                                           $unicode
     * @param array<int, int>                                            $rivals
     * @param array<int, array{Nfa|null, array<int, bool>, string|null}> $built
     *
     * @throws ComplexityException
     */
    private function cell(int $index, Nfa $candidate, array $unicode, array $rivals, array $built, NfaComposer $composer): FirstMatchCell
    {
        $takenBy = [];
        $unread = [];
        $earlier = [];
        foreach ($rivals as $j) {
            [$other, $otherUnicode] = $built[$j];
            \assert(null !== $other);
            if ($otherUnicode !== $unicode) {
                $unread[] = $j;

                continue;
            }

            [$left, $right] = PackedNfa::packPair($other, $candidate);
            $shared = (new ProductSearch($this->options))->intersection($left, $right);
            if (null !== $shared) {
                $takenBy[$j] = self::spell($shared, $left, $unicode);
                $earlier[$j] = $other;
            }
        }

        if ([] === $earlier) {
            [$left, $right] = PackedNfa::packPair($candidate, $candidate);
            $own = (new ProductSearch($this->options))->intersection($left, $right);

            return new FirstMatchCell($index, null !== $own, null === $own ? null : self::spell($own, $left, $unicode), [], $unread);
        }

        [$left, $right] = PackedNfa::packPair($candidate, 1 === \count($earlier) ? array_values($earlier)[0] : $composer->union(...array_values($earlier)));
        $kept = (new ProductSearch($this->options))->difference($left, new LazyDfa($right, $this->options->maxDfaStates));
        if (null !== $kept) {
            return new FirstMatchCell($index, true, self::spell($kept, $left, $unicode), $takenBy, $unread);
        }

        // Kept nothing: by one earlier candidate alone, or only by several.
        $shadowedBy = null;
        foreach ($earlier as $j => $other) {
            [$left, $right] = PackedNfa::packPair($candidate, $other);
            if (null === (new ProductSearch($this->options))->difference($left, new LazyDfa($right, $this->options->maxDfaStates))) {
                $shadowedBy = $j;

                break;
            }
        }

        return new FirstMatchCell($index, false, null, $takenBy, $unread, shadowedBy: $shadowedBy);
    }

    /**
     * @throws ComplexityException
     */
    private function nfa(string $pattern): Nfa
    {
        return $this->nfas[$pattern] ??= $this->solver->nfa($pattern, $this->options);
    }

    /**
     * The parts of a string of classes, cut at each separator: a byte for
     * a field read as bytes, else the UTF-8 of a code point.
     *
     * @param list<int>        $classes
     * @param array<int, bool> $unicode
     *
     * @return list<string>
     */
    private static function spell(array $classes, PackedNfa $nfa, array $unicode): array
    {
        $parts = [];
        $part = '';
        foreach ($classes as $class) {
            $codePoint = $nfa->alphabet->representative[$class];
            if ($codePoint >= NfaComposer::SEPARATOR) {
                $parts[] = $part;
                $part = '';

                continue;
            }

            $part .= ($unicode[\count($parts)] ?? false) ? (CodePointHelper::toString($codePoint) ?? '') : \chr($codePoint);
        }

        $parts[] = $part;

        return $parts;
    }
}
