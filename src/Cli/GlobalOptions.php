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

namespace PHPRegex\Cli;

/**
 * @internal
 */
final class GlobalOptions
{
    public function __construct(
        public readonly bool $quiet,
        public readonly ?bool $ansi,
        public readonly bool $help,
        public readonly bool $visuals,
        public readonly ?string $phpVersion,
        public readonly ?string $error,
        public readonly ?string $pcreVersion = null,
        /**
         * Where the engine's answers about Unicode classes are kept between
         * runs, nowhere by default.
         */
        public readonly ?string $cacheDirectory = null,
        /**
         * The LC_CTYPE locale the patterns are judged under, the --locale
         * option.
         */
        public readonly ?string $locale = null,
    ) {}
}
