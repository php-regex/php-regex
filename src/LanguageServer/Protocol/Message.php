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
 * Represents a JSON-RPC message in the Language Server Protocol.
 *
 * @internal
 */
final readonly class Message
{
    /**
     * @param array<string, mixed>|null $params
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        public string $jsonrpc,
        public ?string $method = null,
        public int|string|null $id = null,
        public ?array $params = null,
        public mixed $result = null,
        public ?array $error = null,
    ) {}

    /**
     * Read a message from stdin following LSP protocol.
     */
    public static function readFromStdin(): ?self
    {
        return self::readFrom(\STDIN);
    }

    /**
     * Read a message from a stream following LSP protocol.
     *
     * @param resource $stream
     */
    public static function readFrom($stream): ?self
    {
        $headers = [];

        // Read headers until empty line
        while (true) {
            $line = fgets($stream);
            if (false === $line) {
                return null;
            }

            $line = trim($line);
            if ('' === $line) {
                break;
            }

            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
                $headers[strtolower($matches[1])] = $matches[2];
            }
        }

        if (!isset($headers['content-length'])) {
            return null;
        }

        $contentLength = (int) $headers['content-length'];
        if ($contentLength <= 0) {
            return null;
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

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return null;
        }

        /** @var string $jsonrpc */
        $jsonrpc = isset($data['jsonrpc']) && \is_string($data['jsonrpc']) ? $data['jsonrpc'] : '2.0';
        /** @var string|null $method */
        $method = isset($data['method']) && \is_string($data['method']) ? $data['method'] : null;
        /** @var int|string|null $id */
        $id = isset($data['id']) && (\is_int($data['id']) || \is_string($data['id'])) ? $data['id'] : null;
        /** @var array<string, mixed>|null $params */
        $params = isset($data['params']) && \is_array($data['params']) ? $data['params'] : null;
        /** @var array<string, mixed>|null $error */
        $error = isset($data['error']) && \is_array($data['error']) ? $data['error'] : null;

        return new self(
            jsonrpc: $jsonrpc,
            method: $method,
            id: $id,
            params: $params,
            result: $data['result'] ?? null,
            error: $error,
        );
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
}
