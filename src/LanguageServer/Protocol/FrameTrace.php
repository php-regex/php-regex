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

namespace PHPRegex\LanguageServer\Protocol;

/**
 * The line the frame log writes for a frame read or written: its kind, its
 * method and id under "messages", and its payload as JSON under "verbose",
 * cut on a character boundary past MAX_PAYLOAD_BYTES so a document's text
 * or a pattern never floods the log, nor breaks its UTF-8.
 *
 * @internal
 */
final class FrameTrace
{
    /**
     * The bytes of a payload a verbose line carries at most.
     */
    public const MAX_PAYLOAD_BYTES = 1024;

    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct() {}

    /**
     * The line for a frame read from the client, or null when the trace is
     * off.
     */
    public static function inbound(Message|InvalidMessage $message, TraceLevel $level): ?string
    {
        if (TraceLevel::Off === $level) {
            return null;
        }

        if ($message instanceof InvalidMessage) {
            return \sprintf('trace: received an invalid message, answered %d %s', $message->code, $message->message);
        }

        $line = match (true) {
            null === $message->method => \sprintf('trace: received response (id %s)', self::id($message->id)),
            null === $message->id => \sprintf('trace: received notification "%s"', $message->method),
            default => \sprintf('trace: received request "%s" (id %s)', $message->method, self::id($message->id)),
        };

        $params = $message->params;

        return self::withPayload($line, null === $params ? null : json_encode($params, self::JSON_FLAGS), $level);
    }

    /**
     * The line for a frame the server writes, its JSON text as written, or
     * null when the trace is off.
     */
    public static function outbound(string $json, TraceLevel $level): ?string
    {
        $message = TraceLevel::Off === $level ? null : json_decode($json, true);
        if (!\is_array($message)) {
            return null;
        }

        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;
        $id = \is_int($id) || \is_string($id) ? $id : null;

        if (\is_string($method)) {
            $line = \array_key_exists('id', $message)
                ? \sprintf('trace: sent request "%s" (id %s)', $method, self::id($id))
                : \sprintf('trace: sent notification "%s"', $method);
            $params = $message['params'] ?? null;

            return self::withPayload($line, null === $params ? null : json_encode($params, self::JSON_FLAGS), $level);
        }

        if (\array_key_exists('error', $message)) {
            $error = $message['error'];
            $code = \is_array($error) && \is_int($error['code'] ?? null) ? $error['code'] : 0;

            return self::withPayload(\sprintf('trace: sent error response (id %s), %d', self::id($id), $code), json_encode($error, self::JSON_FLAGS), $level);
        }

        // A null result is the answer itself, written as such.
        return self::withPayload(\sprintf('trace: sent response (id %s)', self::id($id)), json_encode($message['result'] ?? null, self::JSON_FLAGS), $level);
    }

    /**
     * The text, cut to at most $maxBytes bytes before a character that would
     * not fit whole, with the bytes left out counted after it.
     */
    public static function truncate(string $text, int $maxBytes): string
    {
        $length = \strlen($text);
        if ($length <= $maxBytes) {
            return $text;
        }

        // The first byte left out: a continuation byte belongs to the
        // character before the cut, which is left out whole.
        $end = $maxBytes;
        while ($end > 0 && 0x80 === (\ord($text[$end]) & 0xC0)) {
            $end--;
        }

        $more = $length - $end;

        return \sprintf('%s… (%d more %s)', substr($text, 0, $end), $more, 1 === $more ? 'byte' : 'bytes');
    }

    /**
     * @param string|false|null $payload the payload as JSON; null when the
     *                                   frame carries none
     */
    private static function withPayload(string $line, string|false|null $payload, TraceLevel $level): string
    {
        if (TraceLevel::Verbose !== $level || !\is_string($payload)) {
            return $line;
        }

        return $line.': '.self::truncate($payload, self::MAX_PAYLOAD_BYTES);
    }

    private static function id(int|string|null $id): string
    {
        return match (true) {
            null === $id => 'null',
            \is_int($id) => (string) $id,
            default => '"'.$id.'"',
        };
    }
}
