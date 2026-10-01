<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Extraction;

/**
 * Whether a source file fits in the memory left for analysing it.
 *
 * Tokenizing costs around 55 times the size of the file and building an AST
 * roughly twice that, so a couple of megabytes of generated PHP — a call map,
 * a fixture dump — is enough to exhaust a default 128M limit. A fatal error
 * cannot be caught: it takes down the worker, and with it every result of the
 * run. Skipping the file loses the patterns of that one file instead.
 *
 * @internal
 */
final class MemoryBudget
{
    /**
     * Bytes of memory token_get_all() needs per byte of source.
     */
    public const TOKENIZE_FACTOR = 60;

    /**
     * Bytes of memory building an AST needs per byte of source.
     */
    public const PARSE_FACTOR = 110;

    public static function allows(string $content, int $factor): bool
    {
        $limit = self::limitInBytes();
        if ($limit <= 0) {
            // No limit configured: nothing to stay under.
            return true;
        }

        $available = $limit - memory_get_usage(true);

        return $available > 0 && \strlen($content) * $factor <= $available;
    }

    /**
     * The memory_limit ini value in bytes, or -1 when unlimited.
     */
    private static function limitInBytes(): int
    {
        $limit = ini_get('memory_limit');
        if (!\is_string($limit) || '' === $limit) {
            return -1;
        }

        $limit = trim($limit);
        $unit = strtolower($limit[\strlen($limit) - 1]);
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
