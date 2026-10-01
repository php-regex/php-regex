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

namespace PHPRegex\Parser\Token;

/**
 * Represents a single token from the lexer.
 *
 * A token knows where it starts and how much of the pattern it covers, which
 * is not the same as the length of its value: the lexer strips a backslash
 * from "\d", reads "\p{^Greek}" back as "{^Greek}", and keeps only the name
 * of "\N{LATIN SMALL LETTER A}". Whoever needs the text as it was written
 * reads it back from the pattern rather than guessing it from the value.
 */
final readonly class Token
{
    /**
     * Bytes of the pattern this token was cut from.
     */
    public int $sourceLength;

    /**
     * @param int|null $sourceLength defaults to the length of the value, which
     *                               is right for every token the lexer does not
     *                               rewrite
     */
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $position,
        ?int $sourceLength = null,
    ) {
        $this->sourceLength = $sourceLength ?? \strlen($value);
    }

    /**
     * Offset just past the token, in the pattern it was read from.
     */
    public function end(): int
    {
        return $this->position + $this->sourceLength;
    }
}
