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

use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Represents a JSON-RPC message in the Language Server Protocol.
 *
 * @internal
 */
final class Message
{
    /**
     * @param array<string, mixed>|null $params
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly ?string $method = null,
        public readonly int|string|null $id = null,
        public readonly ?array $params = null,
        public readonly mixed $result = null,
        public readonly ?array $error = null,
    ) {}

    /**
     * Read a message from stdin following LSP protocol.
     */
    public static function readFromStdin(): self|InvalidMessage|null
    {
        return self::readFrom(\STDIN);
    }

    /**
     * Read a message from a stream following LSP protocol.
     *
     * @param resource $stream
     *
     * @return self|InvalidMessage|null null at the end of the input; an
     *                                  InvalidMessage for a message that was
     *                                  read but cannot be used, which the
     *                                  session answers and goes on
     */
    public static function readFrom($stream): self|InvalidMessage|null
    {
        $contentLength = null;
        $inHeaders = false;

        // Read headers until empty line
        while (true) {
            $line = fgets($stream);
            if (false === $line) {
                return null;
            }

            $line = trim($line);
            if ('' === $line) {
                if (!$inHeaders) {
                    // A blank line between two messages.
                    continue;
                }

                break;
            }

            $inHeaders = true;

            // Matched at the end of the line: a body sent without a
            // Content-Length has no known end, and runs into the header of
            // the next message on the same line. That header is where
            // reading starts again.
            if (LibraryPcre::match('/content-length:[ \t]*+(\d++)$/i', $line, $matches)) {
                $contentLength = (int) $matches[1];
            }
        }

        if (null === $contentLength) {
            return InvalidMessage::parseError();
        }

        $content = '';
        while (\strlen($content) < $contentLength) {
            $remaining = $contentLength - \strlen($content);
            $chunk = fread($stream, max(1, $remaining));

            // A closed connection reads as an empty string, not as false:
            // testing for false alone spins the server at full speed once
            // the editor goes away.
            if (false === $chunk || '' === $chunk) {
                return null;
            }

            $content .= $chunk;
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return InvalidMessage::parseError();
        }

        // Only an object is a message: JSON decodes {} and [] alike.
        if (!\is_array($data) || !str_starts_with(ltrim($content, " \t\n\r"), '{')) {
            return InvalidMessage::invalidRequest();
        }

        return self::fromObject($data);
    }

    /**
     * Check if this is a request (has id and method).
     */
    public function isRequest(): bool
    {
        return null !== $this->id && null !== $this->method;
    }

    /**
     * Check if this is a notification (has method but no id).
     */
    public function isNotification(): bool
    {
        return null === $this->id && null !== $this->method;
    }

    /**
     * Check if this is a response (has id but no method).
     */
    public function isResponse(): bool
    {
        return null !== $this->id && null === $this->method;
    }

    /**
     * The message a JSON-RPC 2.0 object holds, or the invalid request it is.
     *
     * @param array<mixed> $data
     */
    private static function fromObject(array $data): self|InvalidMessage
    {
        $id = $data['id'] ?? null;
        $usableId = \is_int($id) || \is_string($id) ? $id : null;
        $method = $data['method'] ?? null;
        // A null "params" is read as no params: some clients send one.
        $params = $data['params'] ?? null;

        $valid = '2.0' === ($data['jsonrpc'] ?? null)
            && $usableId === $id
            && (null === $params || \is_array($params))
            && (\array_key_exists('method', $data)
                ? \is_string($method)
                : \array_key_exists('result', $data) || \array_key_exists('error', $data));

        if (!$valid) {
            return InvalidMessage::invalidRequest($usableId);
        }

        /** @var array<string, mixed>|null $params */
        $params = \is_array($params) ? $params : null;
        /** @var array<string, mixed>|null $error */
        $error = \is_array($data['error'] ?? null) ? $data['error'] : null;

        return new self(
            jsonrpc: '2.0',
            method: \is_string($method) ? $method : null,
            id: $usableId,
            params: $params,
            result: $data['result'] ?? null,
            error: $error,
        );
    }
}
