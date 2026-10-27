<?php

declare(strict_types=1);

namespace PHPRegex\Build\Tests;

/**
 * What the original read, from the directory TWIN_REFERENCE names: the
 * patterns and targets (patterns.json), the md5 of each reading of each
 * pattern (*.jsonl, as build/verify/run-witness.php --hash writes them) and
 * the public surface (api.json).
 */
final class Reference
{
    /**
     * @var array{targets: list<array{php: int, pcre: string, locale: string}>, patterns: list<array{text?: string, base64?: string}>}|null
     */
    private static $patterns;

    /**
     * @var array<int, list<array<string, string>>>|null
     */
    private static $witnesses;

    public static function directory(): string
    {
        $directory = getenv('TWIN_REFERENCE');
        if (!\is_string($directory) || !is_dir($directory)) {
            throw new \RuntimeException('TWIN_REFERENCE names no directory: write the original\'s readings first (build/verify/README.md).');
        }

        return rtrim($directory, '/');
    }

    /**
     * @return array{targets: list<array{php: int, pcre: string, locale: string}>, patterns: list<array{text?: string, base64?: string}>}
     */
    public static function patterns(): array
    {
        return self::$patterns ?? self::$patterns = json_decode((string) file_get_contents(self::directory().'/patterns.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    public static function pattern(int $index): string
    {
        $entry = self::patterns()['patterns'][$index];

        return isset($entry['base64']) ? (string) base64_decode($entry['base64'], true) : (string) $entry['text'];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function witnesses(int $index): array
    {
        if (null === self::$witnesses) {
            self::$witnesses = [];
            foreach (glob(self::directory().'/*.jsonl') ?: [] as $file) {
                foreach (file($file, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                    self::$witnesses[$row['index']] = $row['witnesses'];
                }
            }
        }

        if (!isset(self::$witnesses[$index])) {
            throw new \RuntimeException(sprintf('The reference holds no witness of pattern #%d.', $index));
        }

        return self::$witnesses[$index];
    }

    /**
     * @return array<string, mixed>
     */
    public static function api(): array
    {
        return json_decode((string) file_get_contents(self::directory().'/api.json'), true, 512, \JSON_THROW_ON_ERROR);
    }
}
