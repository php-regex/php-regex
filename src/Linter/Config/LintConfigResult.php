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
 * What regex.json and regex.dist.json hold, merged, once they read as
 * LintConfigSchema says: LintConfig, every key optional.
 *
 * @phpstan-type LintConfig array{
 *     '$schema'?: string,
 *     '$id'?: string,
 *     paths?: string|list<string>,
 *     exclude?: string|list<string>,
 *     phpVersion?: string|int,
 *     pcreVersion?: string,
 *     extraction?: array{interop?: list<string>, functions?: list<string>},
 *     jobs?: int,
 *     format?: string,
 *     ide?: string,
 *     checks?: array{
 *         validation?: bool,
 *         redos?: array{enabled?: bool, mode?: string, threshold?: string},
 *         optimizations?: array{
 *             enabled?: bool,
 *             minSavings?: int,
 *             options?: array{
 *                 digits?: bool,
 *                 word?: bool,
 *                 ranges?: bool,
 *                 canonicalizeCharClasses?: bool,
 *                 possessive?: bool,
 *                 factorize?: bool,
 *                 minQuantifierCount?: int,
 *                 verifyWithAutomata?: bool,
 *             },
 *         },
 *         lint?: array{enabled?: bool, rules?: array<string, bool>},
 *         complexity?: array{enabled?: bool, threshold?: int},
 *     },
 * }
 *
 * @internal
 */
final class LintConfigResult
{
    /**
     * @param LintConfig         $config
     * @param array<int, string> $files
     * @param list<string>       $warnings what the files hold that is ignored, the run going on
     */
    public function __construct(
        public readonly array $config,
        public readonly array $files,
        public readonly ?string $error = null,
        public readonly array $warnings = [],
    ) {}
}
