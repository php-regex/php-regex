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

namespace PHPRegex\Parser\Json;

use PHPRegex\Parser\Exception\JsonEncodingException;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Utf8;

/**
 * One JSON document as every command prints it: pretty-printed, slashes and
 * Unicode unescaped, ending with exactly one newline.
 *
 * A string that is valid UTF-8 is written as it is: JSON escapes control
 * characters itself, losslessly. In a string that is not, each byte that
 * belongs to no valid sequence is written as the text \xNN (uppercase hex)
 * and the rest kept, so encoding never fails on a value.
 *
 * Objects are written through their own mapping (\JsonSerializable) and
 * backed enums through their value, never by reflecting public properties:
 * any other object is a mapping missing, and refused.
 *
 * @internal
 */
final class JsonDocument
{
    /**
     * Where a run stopped, the "stage" of the error envelope. An open
     * vocabulary: a minor may add a stage.
     */
    public const STAGE_USAGE = 'usage';

    public const STAGE_CONFIG = 'config';

    /**
     * The PHP to judge for runs an engine the library does not judge.
     */
    public const STAGE_TARGET = 'target';

    public const STAGE_COLLECT = 'collect';

    public const STAGE_PATTERN = 'pattern';

    public const STAGE_ANALYZE = 'analyze';

    public const STAGE_INTERNAL = 'internal';

    private const FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /**
     * @param array<mixed> $document
     *
     * @throws JsonEncodingException when a value has no JSON form (a non-finite float, an object with no mapping)
     */
    public static function encode(array $document): string
    {
        try {
            return json_encode(self::escapeArray($document), self::FLAGS)."\n";
        } catch (\JsonException $e) {
            throw new JsonEncodingException('Failed to encode JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The error envelope: {"error", "stage"}, then the sibling keys given.
     *
     * @param array<string, mixed> $extra
     */
    public static function error(string $message, string $stage, array $extra = []): string
    {
        return self::encode(['error' => $message, 'stage' => $stage] + $extra);
    }

    /**
     * Each byte of a sequence that is not valid UTF-8 as the text \xNN; a
     * valid sequence, control characters included, kept as it is. The one
     * spelling of an invalid byte in every machine report, JSON or not.
     *
     * A caret snippet ("Line N: " and an excerpt, then a line of spaces
     * and a caret) counts the columns before its caret in bytes when they
     * are no UTF-8: its caret is moved under the same character once the
     * bytes before it are spelled, whatever text is around the snippet.
     */
    public static function spellInvalidBytes(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $lines = explode("\n", $text);
        $spelled = [];
        foreach ($lines as $index => $line) {
            $indent = \strlen($line) - 1;
            $excerpt = $lines[$index - 1] ?? '';
            if ($indent >= 0 && '^' === $line[$indent] && $indent === strspn($line, ' ') && self::isSnippetLine($excerpt) && !mb_check_encoding((string) substr($excerpt, 0, $indent), 'UTF-8')) {
                $spelled[] = str_repeat(' ', self::spelledColumns($excerpt, $indent)).'^';

                continue;
            }

            $spelled[] = self::spell($line);
        }

        return implode("\n", $spelled);
    }

    /**
     * Whether a line opens as the line of a caret snippet: "Line N: ".
     */
    private static function isSnippetLine(string $line): bool
    {
        $colon = strpos($line, ': ');

        return 0 === strncmp($line, 'Line ', strlen('Line ')) && false !== $colon && Ascii::isDigit((string) substr($line, 5, $colon - 5));
    }

    /**
     * The columns the first bytes of a line take once its invalid bytes
     * are spelled: four for an invalid byte, two for a wide character, one
     * for any other, as a snippet counts them; a byte past the end of the
     * line is one column.
     */
    private static function spelledColumns(string $line, int $bytes): int
    {
        $columns = 0;
        $end = min($bytes, \strlen($line));
        for ($i = 0; $i < $end;) {
            $sequence = (string) substr($line, $i, Utf8::width($line, $i));
            if (mb_check_encoding($sequence, 'UTF-8')) {
                $columns += mb_strwidth($sequence, 'UTF-8') > 1 ? 2 : 1;
                $i += \strlen($sequence);

                continue;
            }

            $columns += 4;
            ++$i;
        }

        return $columns + $bytes - $end;
    }

    private static function spell(string $text): string
    {
        $spelled = '';
        $length = \strlen($text);
        for ($i = 0; $i < $length;) {
            $sequence = (string) substr($text, $i, Utf8::width($text, $i));
            // The lead byte announces the length; mb_check_encoding() then
            // rejects a short, overlong or surrogate sequence.
            if (mb_check_encoding($sequence, 'UTF-8')) {
                $spelled .= $sequence;
                $i += \strlen($sequence);

                continue;
            }

            $spelled .= \sprintf('\\x%02X', \ord($text[$i]));
            ++$i;
        }

        return $spelled;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function escapeArray(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = self::escapeValue($item);
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function escapeValue($value)
    {
        if (\is_string($value)) {
            return self::spellInvalidBytes($value);
        }

        if (\is_array($value)) {
            return self::escapeArray($value);
        }

        if ($value instanceof \JsonSerializable) {
            return self::escapeValue($value->jsonSerialize());
        }

        if ($value instanceof \PHPRegex\Parser\BackedEnum) {
            return self::escapeValue($value->value);
        }

        if (\is_object($value)) {
            throw new JsonEncodingException(\sprintf('No JSON mapping for an object of class %s.', get_class($value)));
        }

        return $value;
    }
}
