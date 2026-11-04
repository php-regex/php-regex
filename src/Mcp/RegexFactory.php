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

use PHPRegex\Mcp\Exception\TargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Toolkit\Regex;

/**
 * The facade a call runs on: judged for the PHP and PCRE2 the call names,
 * else for the server's target, else for the running PHP. A PHP that ships
 * PCRE1 is refused as the CLI refuses it: the library judges PCRE2 only.
 *
 * @internal
 *
 * @phpstan-type Target array{php_version?: string, pcre_version?: string, locale?: string}
 */
final class RegexFactory
{
    /**
     * The first PHP that ships PCRE2, as a PHP_VERSION_ID.
     */
    private const FIRST_PCRE2_PHP = 70300;

    /**
     * @var array<string, Regex> one facade per target, kept for the session
     */
    private array $instances = [];

    /**
     * @param Target $target the server's target, the default of every call
     */
    public function __construct(private readonly array $target = []) {}

    /**
     * @param array<string, mixed> $arguments the call's arguments; php_version and pcre_version, when given, win over the server's
     *
     * @throws TargetException when the target cannot be judged for
     */
    public function for(array $arguments): Regex
    {
        $options = [];
        if (isset($this->target['locale'])) {
            $options['locale'] = $this->target['locale'];
        }
        foreach (['php_version', 'pcre_version'] as $key) {
            $value = $arguments[$key] ?? $this->target[$key] ?? null;
            if (null === $value) {
                continue;
            }
            if (!\is_string($value) && !\is_int($value)) {
                throw new TargetException(\sprintf('Invalid option: "%s" must be a string, %s given.', $key, get_debug_type($value)), 'usage');
            }
            $options[$key] = (string) $value;
        }

        $key = (string) json_encode($options);

        return $this->instances[$key] ??= $this->create($options);
    }

    /**
     * @param array{php_version?: string, pcre_version?: string, locale?: string} $options
     */
    private function create(array $options): Regex
    {
        try {
            $regex = Regex::create($options);
        } catch (InvalidRegexOptionException $e) {
            throw new TargetException('Invalid option: '.$e->getMessage(), 'usage', $e);
        }

        if ($regex->target()->phpVersionId < self::FIRST_PCRE2_PHP) {
            $named = $options['php_version'] ?? '';

            throw new TargetException(\sprintf('"php_version" names PHP %s: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.', $named), 'target');
        }

        return $regex;
    }
}
