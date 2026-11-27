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

namespace PHPRegex\Tests\Support;

/**
 * The front matter of a page of the site, as Jekyll reads it. The pages
 * write it in three shapes, which are the three read here: a scalar (JSON
 * when quoted, a number or a word otherwise), a list under a key, and a
 * map under a key. What a page writes in another shape is not read.
 */
final class FrontMatter
{
    /**
     * @return array<string, mixed>
     */
    public static function of(string $page): array
    {
        $text = (string) file_get_contents($page);
        if (1 !== preg_match('/\A---\n(.*?)\n---(?:\n|\z)/s', $text, $parts)) {
            return [];
        }

        return self::block(preg_split('/\R/', rtrim($parts[1], "\n")) ?: [], 0);
    }

    /**
     * The keys of a block, each with its value: the lines of the block
     * stand at the indent given, and what a key holds is indented deeper.
     *
     * @param list<string> $lines
     *
     * @return array<string, mixed>
     */
    private static function block(array $lines, int $indent): array
    {
        $block = [];
        while ([] !== $lines) {
            $line = array_shift($lines);
            if ('' === trim($line) || 1 !== preg_match('/^\s{'.$indent.'}\K(?<key>\w+):(?: (?<value>.+))?$/', $line, $field)) {
                continue;
            }

            $deeper = [];
            while ([] !== $lines && '' !== trim($lines[0]) && \strlen($lines[0]) - \strlen(ltrim($lines[0])) > $indent) {
                $deeper[] = array_shift($lines);
            }

            if (!isset($field['value']) || '' === $field['value']) {
                $block[$field['key']] = [] === $deeper ? null : self::under($deeper);

                continue;
            }

            $block[$field['key']] = self::scalar($field['value']);
        }

        return $block;
    }

    /**
     * What a key that names no value on its line holds: a list, when its
     * first line opens an item, a map otherwise.
     *
     * @param list<string> $lines
     *
     * @return array<int|string, mixed>
     */
    private static function under(array $lines): array
    {
        $pad = \strlen($lines[0]) - \strlen(ltrim($lines[0]));
        if (1 === preg_match('/^\s*-(?: |$)/', $lines[0])) {
            $list = [];
            foreach ($lines as $line) {
                if (1 === preg_match('/^\s*-(?: (?<item>.+))?$/', $line, $item)) {
                    $list[] = self::scalar($item['item'] ?? '');
                }
            }

            return $list;
        }

        return self::block($lines, $pad);
    }

    private static function scalar(string $value): mixed
    {
        if ('"' === $value[0]) {
            return json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        }

        return \is_numeric($value) ? (int) $value : $value;
    }
}
