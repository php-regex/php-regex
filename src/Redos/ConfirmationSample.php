<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Redos;

/**
 * Captures one confirmation probe sample.
 */
final readonly class ConfirmationSample implements \JsonSerializable
{
    public function __construct(
        public int $inputLength,
        public float $durationMs,
        public ?string $inputPreview = null,
        public ?int $pregErrorCode = null,
        public ?string $pregError = null,
    ) {}

    /**
     * @return array{
     *     input_length: int,
     *     duration_ms: float,
     *     input_preview: string|null,
     *     preg_error_code: int|null,
     *     preg_error: string|null,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'input_length' => $this->inputLength,
            'duration_ms' => $this->durationMs,
            'input_preview' => $this->inputPreview,
            'preg_error_code' => $this->pregErrorCode,
            'preg_error' => $this->pregError,
        ];
    }
}
