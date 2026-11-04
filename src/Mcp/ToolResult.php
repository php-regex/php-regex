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

namespace PHPRegex\Mcp;

use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Validation\ValidationResult;

/**
 * What a tool answers: a text for a client that reads prose, and the
 * structured document for one that reads data. The document is the one
 * the CLI prints for the same question with --json, so a client and a
 * script read one contract. A failure carries the CLI's error envelope
 * and is flagged, never raised: the client has to read the message.
 */
final class ToolResult
{
    /**
     * @param array<string, mixed> $structured the document, as the CLI's --json prints it
     */
    private function __construct(
        public readonly string $text,
        public readonly array $structured,
        public readonly bool $isError,
    ) {}

    /**
     * A success: the text, and the document the CLI prints for the same
     * question, its strings spelled as the CLI spells them (an invalid byte
     * as \xNN).
     *
     * @param array<string, mixed> $document
     */
    public static function document(string $text, array $document): self
    {
        return new self($text, self::asCliDocument($document), false);
    }

    /**
     * A failure: the message, and the CLI's error envelope, {"error",
     * "stage"} then the validation of the pattern when the pattern is what
     * failed.
     */
    public static function failure(string $message, string $stage, ?ValidationResult $validation = null): self
    {
        $envelope = ['error' => $message, 'stage' => $stage];
        if (null !== $validation) {
            $envelope['validation'] = $validation;
        }

        return new self($message, self::asCliDocument($envelope), true);
    }

    /**
     * The document as the CLI prints it, read back: the same objects, the
     * same spelling of every string.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function asCliDocument(array $document): array
    {
        $decoded = json_decode(JsonDocument::encode($document), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        $typed = [];
        foreach ($decoded as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
