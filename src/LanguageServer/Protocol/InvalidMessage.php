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
 * A message read from the client that cannot be used, with the JSON-RPC
 * error that answers it.
 *
 * @internal
 */
final readonly class InvalidMessage
{
    /**
     * JSON-RPC "parse error": the body is not JSON, or has no known length.
     */
    public const PARSE_ERROR = -32700;

    /**
     * JSON-RPC "invalid request": JSON that is not a request object.
     */
    public const INVALID_REQUEST = -32600;

    /**
     * @param int|string|null $id the id the message carries, when it can be read
     */
    private function __construct(
        public int $code,
        public string $message,
        public int|string|null $id = null,
    ) {}

    public static function parseError(): self
    {
        return new self(self::PARSE_ERROR, 'Parse error');
    }

    public static function invalidRequest(int|string|null $id = null): self
    {
        return new self(self::INVALID_REQUEST, 'Invalid Request', $id);
    }
}
