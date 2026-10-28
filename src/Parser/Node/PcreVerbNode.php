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

namespace PHPRegex\Parser\Node;

use PHPRegex\Parser\NodeVisitorInterface;

/**
 * Represents a PCRE verb.
 */
final class PcreVerbNode extends AbstractNode
{
    /**
     * The verb's name as PCRE2 documents it: MARK for a mark written
     * "(*:x)", FAIL for "(*F)", LIMIT_DEPTH for "(*LIMIT_DEPTH=5)".
     */
    public readonly string $name;

    /**
     * What follows the name: the name of a mark or of a cut, "x" in
     * "(*MARK:x)" and "(*PRUNE:x)", the value of a limit, "5" in
     * "(*LIMIT_DEPTH=5)". Null when the verb takes none, "" when it is
     * written and empty, as in "(*MARK:)".
     */
    public readonly ?string $argument;

    /**
     * @param string $verb the verb as the tree keeps it, between "(*" and
     *                     ")" with a mark written "(*:x)" spelled "MARK:x"
     */
    public function __construct(
        public readonly string $verb,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);

        [$this->name, $this->argument] = self::split($verb);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitPcreVerb($this);
    }

    /**
     * @return array{string, ?string}
     */
    private static function split(string $verb): array
    {
        // "(*:x)" is "(*MARK:x)" written short.
        if ('' !== $verb && (':' === $verb[0] || '=' === $verb[0])) {
            $verb = 'MARK'.$verb;
        }

        $separator = strcspn($verb, ':=');
        $name = substr($verb, 0, $separator);
        $argument = $separator < \strlen($verb) ? substr($verb, $separator + 1) : null;

        return ['F' === $name ? 'FAIL' : $name, $argument];
    }
}
