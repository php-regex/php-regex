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

use PHPRegex\Parser\Internal\Ascii;

/**
 * @internal
 */
final readonly class PcreRuntimeInfo implements \JsonSerializable
{
    public function __construct(
        public string $version,
        public ?string $jitSetting,
        public ?int $backtrackLimit,
        public ?int $recursionLimit,
    ) {}

    public static function fromIni(): self
    {
        $version = 'unknown';
        if (\defined('PCRE_VERSION')) {
            $version = (string) \PCRE_VERSION;
        } elseif (false !== ($pcreVersion = phpversion('pcre'))) {
            $version = (string) $pcreVersion;
        }

        return new self(
            $version,
            self::iniValue('pcre.jit'),
            self::iniInt('pcre.backtrack_limit'),
            self::iniInt('pcre.recursion_limit'),
        );
    }

    /**
     * @return array{
     *     version: string,
     *     jit: string|null,
     *     backtrack_limit: int|null,
     *     recursion_limit: int|null,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'version' => $this->version,
            'jit' => $this->jitSetting,
            'backtrack_limit' => $this->backtrackLimit,
            'recursion_limit' => $this->recursionLimit,
        ];
    }

    private static function iniValue(string $key): ?string
    {
        $value = ini_get($key);
        if (false === $value) {
            return null;
        }

        return (string) $value;
    }

    private static function iniInt(string $key): ?int
    {
        $value = ini_get($key);
        if (false === $value) {
            return null;
        }

        $value = trim((string) $value);
        if ('' === $value || !Ascii::isDigit($value)) {
            return null;
        }

        return (int) $value;
    }
}
