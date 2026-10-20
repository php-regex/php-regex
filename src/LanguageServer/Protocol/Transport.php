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
 * Writes the JSON-RPC messages of one session to its stream: the answers to
 * the client's requests, the notifications and the requests the server
 * sends.
 *
 * A response that cannot be encoded as JSON, a byte that is not UTF-8 in
 * the text it quotes for one, is answered with an internal error: the
 * client waits for an answer to each request it sends. A notification or a
 * request of the server's that cannot be encoded is not sent, and the
 * observer is told why.
 *
 * @internal
 */
final class Transport
{
    /**
     * JSON-RPC "internal error".
     */
    public const INTERNAL_ERROR = -32603;

    /**
     * The types of window/logMessage and window/showMessage.
     */
    public const LOG_ERROR = 1;
    public const LOG_WARNING = 2;
    public const LOG_INFO = 3;

    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * Called with the JSON text of each message written, for the frame log.
     *
     * @var (\Closure(string): void)|null
     */
    private ?\Closure $sent = null;

    /**
     * Called with a line saying why a message was not sent.
     *
     * @var (\Closure(string): void)|null
     */
    private ?\Closure $dropped = null;

    /**
     * @param resource|null $stream the stream the messages are written to,
     *                              stdout when null
     */
    public function __construct(private $stream = null) {}

    /**
     * Hand the JSON text of each message written to $sent, after its frame,
     * and the reason a message is not sent to $dropped.
     *
     * @param (\Closure(string): void)|null $sent    null observes nothing
     * @param (\Closure(string): void)|null $dropped null observes nothing
     */
    public function observeWith(?\Closure $sent, ?\Closure $dropped = null): void
    {
        $this->sent = $sent;
        $this->dropped = $dropped;
    }

    /**
     * Send a successful response.
     */
    public function success(int|string $id, mixed $result): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    /**
     * Send an error response.
     *
     * @param int|string|null $id null when the id of the request cannot be read
     */
    public function error(int|string|null $id, int $code, string $message): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }

    /**
     * Send a notification (no response expected).
     *
     * @param array<string, mixed>|null $params
     */
    public function notification(string $method, ?array $params = null): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];

        if (null !== $params) {
            $message['params'] = $params;
        }

        $this->send($message);
    }

    /**
     * Send a request to the client, whose answer carries the same id.
     *
     * @param array<string, mixed>|null $params
     */
    public function request(int|string $id, string $method, ?array $params = null): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];

        if (null !== $params) {
            $message['params'] = $params;
        }

        $this->send($message);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function send(array $message): void
    {
        $json = json_encode($message, self::JSON_FLAGS);
        if (false === $json) {
            $reason = json_last_error_msg();
            $method = $message['method'] ?? null;
            if (!\is_string($method)) {
                // An error frame of an id read from JSON and of an ASCII message always encodes.
                $json = (string) json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $message['id'] ?? null,
                    'error' => ['code' => self::INTERNAL_ERROR, 'message' => 'The response could not be encoded as JSON: '.$reason.'.'],
                ], self::JSON_FLAGS);
            } else {
                if (null !== $this->dropped) {
                    ($this->dropped)(\sprintf('the %s %s could not be encoded as JSON: %s. It was not sent.', $method, \array_key_exists('id', $message) ? 'request' : 'notification', $reason));
                }

                return;
            }
        }

        $stream = $this->stream ?? \STDOUT;
        $contentLength = \strlen($json);
        fwrite($stream, "Content-Length: {$contentLength}\r\n\r\n{$json}");
        fflush($stream);

        if (null !== $this->sent) {
            ($this->sent)($json);
        }
    }
}
