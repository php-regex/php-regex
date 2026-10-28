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

namespace PHPRegex\Redos;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PatternParser;

/**
 * The patterns the risk analysis skips, as a list names them. An entry
 * written with delimiters and modifiers names one pattern: the same body
 * under the same modifiers, whatever the delimiter and the order of the
 * letters. Any other entry, a body, a fragment or a delimited pattern
 * without modifiers, names its body under any delimiters and modifiers.
 * The lint reads its ReDoS ignore list with it.
 */
final class IgnoreList
{
    /**
     * The entries read by their body: each as written, and the body of
     * each delimited one.
     *
     * @var array<string, true>
     */
    private array $forms = [];

    /**
     * The entries that name one pattern, keyed by body and modifiers.
     *
     * @var array<string, true>
     */
    private array $exact = [];

    /**
     * @param array<string> $entries
     */
    public function __construct(array $entries, private readonly ?PcreTarget $target = null)
    {
        foreach ($entries as $entry) {
            $read = $this->read($entry);
            if (null !== $read && '' !== $read[1]) {
                $this->exact[$read[0]."\0".$read[1]] = true;

                continue;
            }

            $this->forms[$entry] = true;
            if (null !== $read) {
                $this->forms[$read[0]] = true;
            }
        }
    }

    public function isEmpty(): bool
    {
        return [] === $this->forms && [] === $this->exact;
    }

    /**
     * Whether the list names the regex: an entry that names one pattern by
     * the regex's body and modifiers, any other by one of the forms given,
     * the regex, its body or fragments of it.
     *
     * @param array<string> $forms
     */
    public function names(string $regex, array $forms): bool
    {
        if ([] !== $this->exact) {
            $read = $this->read($regex);
            if (null !== $read && isset($this->exact[$read[0]."\0".$read[1]])) {
                return true;
            }
        }

        foreach ($forms as $form) {
            if (isset($this->forms[$form])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body of a delimited regex and its modifier letters, sorted and
     * each once, without the spaces and line breaks PHP skips among them.
     * Null for a string that is no delimited regex.
     *
     * @return array{0: string, 1: string}|null
     */
    private function read(string $regex): ?array
    {
        try {
            [$body, $flags] = PatternParser::extractPatternAndFlags($regex, $this->target);
        } catch (ParserException) {
            return null;
        }

        return [$body, count_chars(str_replace([' ', "\n", "\r"], '', $flags), 3)];
    }
}
