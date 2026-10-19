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

namespace PHPRegex\Symfony\Security;

/**
 * One access_control rule on one line, as regex:security and regex:analyze
 * both print it, and the security files both read.
 *
 * @internal
 *
 * @phpstan-import-type AccessRule from SecurityAccessControlReport
 */
final class AccessRuleSummary
{
    /**
     * "#index location  path=...  roles=...", with "scope=..." when the rule
     * is restricted to some methods, a host, some IPs or a channel.
     *
     * @phpstan-param AccessRule $rule
     */
    public static function of(array $rule, string $location): string
    {
        $parts = [
            \sprintf('#%d %s', $rule['index'], $location),
            'path='.self::pathValue($rule['path']),
            'roles='.self::listValue($rule['roles'], 'any'),
        ];

        $scope = self::scope($rule['methods'], $rule['host'], $rule['ips'], $rule['requiresChannel']);
        if ('any' !== $scope) {
            $parts[] = 'scope='.$scope;
        }

        return implode('  ', $parts);
    }

    /**
     * Whether a security file is one the extractor reads: YAML.
     */
    public static function isYamlFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return 'yaml' === $extension || 'yml' === $extension;
    }

    private static function pathValue(?string $path): string
    {
        if (null === $path || '' === $path) {
            return '(any)';
        }

        return $path;
    }

    /**
     * @param array<int, string> $values
     */
    private static function listValue(array $values, string $emptyLabel): string
    {
        if ([] === $values) {
            return $emptyLabel;
        }

        return implode('|', $values);
    }

    /**
     * @param array<int, string> $methods
     * @param array<int, string> $ips
     */
    private static function scope(array $methods, ?string $host, array $ips, ?string $requiresChannel): string
    {
        $parts = [];
        if ([] !== $methods) {
            $parts[] = 'methods='.implode('|', $methods);
        }
        if (null !== $host && '' !== trim($host, " \t\n\r\0\x0B")) {
            $parts[] = 'host='.$host;
        }
        if ([] !== $ips) {
            $parts[] = 'ips='.implode('|', $ips);
        }
        if (null !== $requiresChannel && '' !== trim($requiresChannel, " \t\n\r\0\x0B")) {
            $parts[] = 'channel='.$requiresChannel;
        }

        // A host or a channel written empty restricts nothing.
        return [] === $parts ? 'any' : implode(' • ', $parts);
    }
}
