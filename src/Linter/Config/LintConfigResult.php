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

namespace PHPRegex\Linter\Config;

/**
 * @internal
 */
final readonly class LintConfigResult
{
    /**
     * @param array<string, mixed> $config
     * @param array<int, string>   $files
     */
    public function __construct(
        public array $config,
        public array $files,
        public ?string $error = null,
    ) {}
}
