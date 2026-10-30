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

namespace PHPRegex\Parser\Hir;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Path;

/**
 * The engine's answers about Unicode classes, kept in a directory between
 * processes: one file per answer, under a directory named for what makes
 * the answer, the library's code (its cache version, which reads this
 * code), PHP and PCRE2, whose release fixes its Unicode tables. A change of
 * any of them opens another directory: an answer is never read by an
 * engine that did not give it.
 *
 * A file holds its key and its sets as text, never code: one that does not
 * read as sorted, disjoint ranges of code points, under its own key, is
 * not taken. Written beside its name, then moved there, for the owner
 * alone; a directory others may write into, or that another user owns, is
 * neither read nor written. Nothing here fails: a directory that cannot be
 * written keeps nothing, and the answer is the engine's again next time.
 *
 * @internal
 */
final class ClassSetStore
{
    private const FORMAT = 'class-set 1';

    private const LAST_CODE_POINT = 0x10FFFF;

    private readonly string $directory;

    /**
     * @param string      $root   the directory the caller names
     * @param string|null $engine what makes an answer, the running one by default
     */
    public function __construct(string $root, ?string $engine = null)
    {
        $this->directory = rtrim($root, '\\/').\DIRECTORY_SEPARATOR.'class-sets-'.substr(hash('sha256', $engine ?? self::engine()), 0, 16);
    }

    /**
     * What makes an answer: the library's code, PHP and PCRE2.
     */
    public static function engine(): string
    {
        return RegexParser::CACHE_VERSION."\n".\PHP_VERSION."\n".\PCRE_VERSION;
    }

    /**
     * The directory this engine's answers are kept in.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The sets kept under the key, by name; null when none is, or the file
     * does not read as them.
     *
     * @return array<string, CharSet>|null
     */
    public function load(string $key): ?array
    {
        $path = $this->path($key);
        $content = is_file($path) && $this->isTrusted() ? @file_get_contents($path) : false;
        $lines = false === $content ? [] : explode("\n", rtrim($content, "\n"));
        if (\count($lines) < 3 || self::FORMAT !== $lines[0] || $key !== $lines[1]) {
            return null;
        }

        $sets = [];
        foreach (\array_slice($lines, 2) as $line) {
            [$name, $ranges] = explode(' ', $line, 2) + ['', ''];
            $set = '' === $name || isset($sets[$name]) ? null : self::read($ranges);
            if (null === $set) {
                return null;
            }

            $sets[$name] = $set;
        }

        return $sets;
    }

    /**
     * Keep the sets under the key, each by its name.
     *
     * @param array<string, CharSet> $sets
     */
    public function save(string $key, array $sets): void
    {
        if (!$this->isTrusted() || (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory))) {
            return;
        }

        $content = self::FORMAT."\n".$key."\n";
        foreach ($sets as $name => $set) {
            $content .= $name.' '.self::write($set)."\n";
        }

        $path = $this->path($key);
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (false === @file_put_contents($temporary, $content) || !@chmod($temporary, 0o600) || !@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function path(string $key): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.hash('sha256', $key).'.set';
    }

    /**
     * Whether the directory is the owner's alone: not one others may write
     * into, nor one another user owns. One not made yet is.
     */
    private function isTrusted(): bool
    {
        if (Path::onWindows() || !is_dir($this->directory)) {
            return true;
        }

        $permissions = @fileperms($this->directory);
        if (false === $permissions || 0 !== ($permissions & 0o022)) {
            return false;
        }

        return !\function_exists('posix_geteuid') || @fileowner($this->directory) === posix_geteuid();
    }

    private static function write(CharSet $set): string
    {
        $parts = [];
        foreach ($set->ranges as [$from, $to]) {
            $parts[] = $from.'-'.$to;
        }

        return implode(',', $parts);
    }

    /**
     * The set the text writes, "from-to" ranges joined by commas, ascending
     * and apart, inside the code points but the surrogates; null for any
     * other text. The empty text is the empty set.
     */
    private static function read(string $text): ?CharSet
    {
        if ('' === $text) {
            return CharSet::empty();
        }

        $ranges = [];
        $after = 0;
        foreach (explode(',', $text) as $part) {
            [$from, $to] = explode('-', $part, 2) + ['', ''];
            // Seven digits hold every code point, and no integer overflows.
            if (!Ascii::isDigit($from) || !Ascii::isDigit($to) || \strlen($from) > 7 || \strlen($to) > 7) {
                return null;
            }

            $from = (int) $from;
            $to = (int) $to;
            if ($from < $after || $to < $from || $to > self::LAST_CODE_POINT || ($from <= 0xDFFF && $to >= 0xD800)) {
                return null;
            }

            $ranges[] = [$from, $to];
            $after = $to + 2;
        }

        return CharSet::fromSorted($ranges);
    }
}
