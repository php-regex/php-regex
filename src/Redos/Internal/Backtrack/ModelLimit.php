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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * Stops the proof: the pattern holds a construct the model leaves out, or
 * the analysis ran out of its budget. Never leaves the analyzer.
 *
 * @internal
 */
final class ModelLimit extends \RuntimeException implements ExceptionInterface
{
    private function __construct(
        string $message,
        public readonly bool $budgetExceeded,
        public readonly bool $approximated = false,
        public readonly ?string $abstraction = null,
    ) {
        parent::__construct($message);
    }

    /**
     * An ambiguity the model found and could neither witness nor disprove:
     * no proof, whatever the class.
     */
    public static function unwitnessed(int $offset): self
    {
        $entry = \sprintf('ambiguity without witness at offset %d', $offset);

        return new self(ucfirst($entry), false, true, $entry);
    }

    public static function outOfModel(string $construct): self
    {
        return new self($construct.' is outside the backtracking model', false);
    }

    /**
     * The worst pump found crosses an atomic body the model keeps with every
     * way through it: the class is not proven.
     */
    public static function approximated(): self
    {
        return new self('The worst pump crosses an over-approximated atomic body', false, true);
    }

    public static function budget(string $what): self
    {
        return new self($what.' over the analysis budget', true);
    }
}
