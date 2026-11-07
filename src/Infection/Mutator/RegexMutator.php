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

namespace PHPRegex\Infection\Mutator;

use Infection\Mutator\Mutator;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPRegex\Infection\Mutation\Family;
use PHPRegex\Infection\Mutation\MutationPipeline;
use PHPRegex\Infection\Site\PatternCall;
use PHPRegex\Parser\Engine\PcreEngine;

/**
 * What the six mutators share: they read a call to a native preg_*()
 * function whose pattern is a string literal, and hand Infection the call
 * once per mutant of their family. They never throw: a pattern the library
 * cannot read, or any failure of its own, means no mutant for that call,
 * since one exception would stop the whole run.
 *
 * @internal
 *
 * @implements Mutator<Node>
 */
abstract class RegexMutator implements Mutator
{
    private ?MutationPipeline $pipeline = null;

    private ?PcreEngine $engine = null;

    final public function __construct() {}

    final public function getName(): string
    {
        return substr(static::class, (int) strrpos(static::class, '\\') + 1);
    }

    final public function canMutate(Node $node): bool
    {
        try {
            $call = PatternCall::fromNode($node);

            return null !== $call && null === ($this->engine ??= new PcreEngine())->compile($call->pattern);
        } catch (\Throwable) {
            // Unreachable from a test: nothing below throws on a call it
            // reads. Infection's generation of mutants is the boundary,
            // though: one throw here would stop the user's whole run.
            return false;
        }
    }

    /**
     * The mutated calls, one at a time: Infection takes each before the next
     * is written.
     *
     * @return \Generator<int, FuncCall>
     */
    final public function mutate(Node $node): \Generator
    {
        try {
            $call = PatternCall::fromNode($node);
            if (null === $call) {
                return;
            }

            foreach (($this->pipeline ??= new MutationPipeline(static::family()))->stream($call) as $mutant) {
                yield $call->withPattern($mutant);
            }
        } catch (\Throwable) {
            // Reached only by a failure the library does not foresee, which
            // must not stop the run either: see canMutate(). The mutants
            // already handed over stay.
            return;
        }
    }

    abstract protected static function family(): Family;
}
