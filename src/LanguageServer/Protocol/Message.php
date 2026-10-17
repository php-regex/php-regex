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
     * The bytes a refused frame is drained in, one read at a time.
     */
    private const DRAIN_CHUNK = 65536;

    /**
     * A response a client sends is told apart by its id without a method:
     * the server never reads its result or error payload, only whether
     * the answer was an error at all.
     *
     * @param array<array-key, mixed>|null $params      positional or named, as JSON-RPC allows
     * @param bool                         $clientError whether a response carries an error instead of a result
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly ?string $method = null,
        public readonly int|string|null $id = null,
        public readonly ?array $params = null,
        public readonly bool $clientError = false,
    ) {}

    /**
     * Whether this response answers with an error rather than a result.
     */
    public function isErrorResponse(): bool
    {
        return null === $this->method && $this->clientError;
    }

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
     * @param resource           $stream
     * @param MessageLimits|null $limits the caps the frame is read
     *                                   under, the production ones
     *                                   when null
     *
     * @return self|InvalidMessage|null null at the end of the input; an
     *                                  InvalidMessage for a message that was
     *                                  read but cannot be used, which the
     *                                  session answers and goes on
     */
    public static function readFrom($stream, ?MessageLimits $limits = null): self|InvalidMessage|null
    {
        $limits ??= new MessageLimits();
        $contentLength = null;
        $inHeaders = false;
        $headerBlock = 0;

        // Read headers until empty line
        while (true) {
            // Header lines that already cost the whole cap cannot be
            // terminated within it: the blank line that would end the
            // block costs bytes past the cap. Refused without waiting
            // for it.
            if ($headerBlock >= $limits->maxHeaderBlock) {
                self::drainRest($stream);

                return InvalidMessage::invalidRequest();
            }

            // A header line never costs more than the block's whole
            // remaining budget: an endless one is refused the moment the
            // budget is spent, not after it was buffered whole.
            $budget = $limits->maxHeaderBlock - $headerBlock;
            $line = fgets($stream, max(1, $budget + 1));
            if (false === $line) {
                return null;
            }

            if (!str_ends_with($line, "\n") && !feof($stream)) {
                self::drainRest($stream);

                return InvalidMessage::invalidRequest();
            }

            // The block counts every byte through the blank line that ends
            // it: a line read under its remaining budget never brings the
            // count past the cap, so the check above is the only refusal.
            $headerBlock += \strlen($line);

            $line = trim($line, " \t\n\r\0\x0B");
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

        // A body the cap refuses still costs its declared bytes: skipping
        // them desyncs the stream the frame after it is read from.
        if ($contentLength > $limits->maxContentLength) {
            self::drainDeclared($stream, $contentLength);

            return InvalidMessage::invalidRequest();
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
     * Discard the rest of the input, in bounded reads: a header block past
     * its cap is a flood whose end never comes, so nothing behind it is
     * ever read as a frame again.
     *
     * @param resource $stream
     */
    private static function drainRest($stream): void
    {
        while (false !== ($chunk = fread($stream, self::DRAIN_CHUNK)) && '' !== $chunk) {
        }
    }

    /**
     * Discard the $length bytes a refused frame declared, in bounded reads:
     * the stream goes on at the frame that follows them.
     *
     * @param resource $stream
     */
    private static function drainDeclared($stream, int $length): void
    {
        while ($length > 0) {
            $chunk = fread($stream, min(self::DRAIN_CHUNK, $length));
            if (false === $chunk || '' === $chunk) {
                return;
            }

            $length -= \strlen($chunk);
        }
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

        $params = \is_array($params) ? $params : null;

        return new self(
            jsonrpc: '2.0',
            method: \is_string($method) ? $method : null,
            id: $usableId,
            params: $params,
            clientError: null === $method && \array_key_exists('error', $data),
        );
    }
}
