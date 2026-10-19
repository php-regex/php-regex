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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Parser\Node\LiteralNode;

/**
 * The UTF-8 character a literal of one byte starts, where the tree holds
 * its bytes apart: without UTF, PCRE ends a class range on the first byte
 * of a multibyte character and starts the next range from its last,
 * "[a-é-\xff]", so the tree holds "\xC3", then "\xA9".
 *
 * @internal
 */
final class SplitCharacter
{
    /**
     * The character, where it starts and where it ends in the source; null
     * when the literal is no lead byte of a whole UTF-8 character written
     * there.
     *
     * @return array{string, int, int}|null
     */
    public static function startedBy(string $source, LiteralNode $literal): ?array
    {
        $lead = 1 === \strlen($literal->value) ? \ord($literal->value) : 0;
        if ($lead < 0xC2 || $lead > 0xF4) {
            return null;
        }

        $start = $literal->getEndPosition() - 1;
        $character = substr($source, $start, $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2));

        return $literal->value === ($source[$start] ?? '') && 1 === mb_strlen($character, 'UTF-8') && mb_check_encoding($character, 'UTF-8')
            ? [$character, $start, $start + \strlen($character)]
            : null;
    }
}
