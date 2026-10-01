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
 * Handles sending JSON-RPC responses to stdout.
 */
final class Response
{
    /**
     * Stream the responses are written to, or null for stdout.
     *
     * @var resource|null
     */
    private static $stream;

    /**
     * Write the responses somewhere else than stdout, which is what an
     * embedding process — or a test — needs to read them back.
     *
     * @param resource|null $stream null restores stdout
     */
    public static function writeTo($stream): void
    {
        self::$stream = $stream;
    }

    /**
     * Send a successful response.
     */
    public static function success(int|string $id, mixed $result): void
    {
        self::send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    /**
     * Send an error response.
     */
    public static function error(int|string $id, int $code, string $message, mixed $data = null): void
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if (null !== $data) {
            $error['data'] = $data;
        }

        self::send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ]);
    }

    /**
     * Send a notification (no response expected).
     *
     * @param array<string, mixed>|null $params
     */
    public static function notification(string $method, ?array $params = null): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];

        if (null !== $params) {
            $message['params'] = $params;
        }

        self::send($message);
    }

    /**
     * Send a raw JSON-RPC message.
     *
     * @param array<string, mixed> $message
     */
    private static function send(array $message): void
    {
        $json = json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            return;
        }

        $stream = self::$stream ?? \STDOUT;
        $contentLength = \strlen($json);
        fwrite($stream, "Content-Length: {$contentLength}\r\n\r\n{$json}");
        fflush($stream);
    }
}
