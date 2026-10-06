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

namespace PHPRegex\Parser\Internal;

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

    public const STAGE_COLLECT = 'collect';

    public const STAGE_PATTERN = 'pattern';

    public const STAGE_INTERNAL = 'internal';

    private const FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /**
     * @param array<mixed> $document
     *
     * @throws JsonEncodingFailure when a value has no JSON form (a non-finite float, an object with no mapping)
     */
    public static function encode(array $document): string
    {
        try {
            return json_encode(self::escapeArray($document), self::FLAGS)."\n";
        } catch (\JsonException $e) {
            throw new JsonEncodingFailure('Failed to encode JSON: '.$e->getMessage(), 0, $e);
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
     */
    public static function spellInvalidBytes(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $spelled = '';
        $length = \strlen($text);
        for ($i = 0; $i < $length;) {
            $sequence = substr($text, $i, self::sequenceLength(\ord($text[$i])));
            // The lead byte announces the length; mb_check_encoding() then
            // rejects a short, overlong or surrogate sequence.
            if (mb_check_encoding($sequence, 'UTF-8')) {
                $spelled .= $sequence;
                $i += \strlen($sequence);

                continue;
            }

            $spelled .= \sprintf('\\x%02X', \ord($text[$i]));
            $i++;
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

    private static function escapeValue(mixed $value): mixed
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

        if ($value instanceof \BackedEnum) {
            return self::escapeValue($value->value);
        }

        if (\is_object($value)) {
            throw new JsonEncodingFailure(\sprintf('No JSON mapping for an object of class %s.', $value::class));
        }

        return $value;
    }

    /**
     * The length of the UTF-8 sequence a lead byte opens; 1 for a byte that
     * opens none, which the caller then spells.
     */
    private static function sequenceLength(int $byte): int
    {
        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }
}
