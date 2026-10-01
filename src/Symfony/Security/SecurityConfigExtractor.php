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
 * Extracts access_control and firewall patterns from security config files.
 *
 * @internal
 *
 * @phpstan-type AccessControlRule array{
 *     file: string,
 *     line: int,
 *     path: ?string,
 *     host: ?string,
 *     roles: array<int, string>,
 *     methods: array<int, string>,
 *     ips: array<int, string>,
 *     allowIf: ?string,
 *     requestMatcher: ?string,
 *     requiresChannel: ?string,
 * }
 * @phpstan-type FirewallRule array{
 *     name: string,
 *     file: string,
 *     line: int,
 *     pattern: ?string,
 *     requestMatcher: ?string,
 * }
 */
final readonly class SecurityConfigExtractor
{
    /**
     * @return array{accessControl: array<AccessControlRule>, firewalls: array<FirewallRule>}
     */
    public function extract(string $path, ?string $environment = null): array
    {
        $lines = @file($path, \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return ['accessControl' => [], 'firewalls' => []];
        }

        return [
            'accessControl' => $this->extractAccessControl($lines, $path, $environment),
            'firewalls' => $this->extractFirewalls($lines, $path, $environment),
        ];
    }

    /**
     * @param array<int, string> $lines
     *
     * @return list<AccessControlRule>
     */
    private function extractAccessControl(array $lines, string $path, ?string $environment): array
    {
        /** @var array<int, AccessControlRule> $rules */
        $rules = [];
        $securityIndent = null;
        $accessIndent = null;
        $currentRuleIndex = null;
        $currentRuleIndent = null;
        /** @var 'roles'|'methods'|'ips'|null $currentListKey */
        $currentListKey = null;
        /** @var int|null $currentListIndent */
        $currentListIndent = null;
        $whenIndent = null;
        $skipWhen = false;

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $indent = \strlen($line) - \strlen($trimmed);
            $key = $this->extractKeyFromLine($line);

            if (null !== $whenIndent && $indent <= $whenIndent) {
                $whenIndent = null;
                $skipWhen = false;
            }

            if (null !== $key && 0 === $indent && str_starts_with($key, 'when@')) {
                $whenIndent = $indent;
                $skipWhen = null !== $environment && $environment !== substr($key, 5);

                continue;
            }

            if (null !== $whenIndent && $skipWhen && $indent > $whenIndent) {
                continue;
            }

            if (null !== $key && 'security' === $key) {
                $securityIndent = $indent;
                $accessIndent = null;
                $currentRuleIndex = null;
                $currentListKey = null;
                $currentListIndent = null;

                continue;
            }

            if (null !== $securityIndent && $indent <= $securityIndent) {
                $securityIndent = null;
                $accessIndent = null;
                $currentRuleIndex = null;
                $currentListKey = null;
                $currentListIndent = null;
            }

            if (null !== $securityIndent && null !== $key && 'access_control' === $key && $indent > $securityIndent) {
                $accessIndent = $indent;
                $currentRuleIndex = null;
                $currentListKey = null;
                $currentListIndent = null;

                continue;
            }

            if (null === $accessIndent) {
                continue;
            }

            if ($indent <= $accessIndent) {
                $accessIndent = null;
                $currentRuleIndex = null;
                $currentListKey = null;
                $currentListIndent = null;

                continue;
            }

            if (preg_match('/^\s*-\s*(.*)$/', $line, $matches)) {
                $currentRuleIndex = \count($rules);
                $currentRuleIndent = $indent;
                $currentListKey = null;
                $currentListIndent = null;

                $rules[$currentRuleIndex] = [
                    'file' => $path,
                    'line' => $index + 1,
                    'path' => null,
                    'host' => null,
                    'roles' => [],
                    'methods' => [],
                    'ips' => [],
                    'allowIf' => null,
                    'requestMatcher' => null,
                    'requiresChannel' => null,
                ];

                $inline = trim($matches[1]);
                if ('' !== $inline) {
                    $pairs = $this->parseInlineMapping($inline);
                    $this->applyRulePairs($rules[$currentRuleIndex], $pairs);
                }

                continue;
            }

            if (null === $currentRuleIndex || null === $currentRuleIndent) {
                continue;
            }

            if ($indent <= $currentRuleIndent) {
                $currentRuleIndex = null;
                $currentListKey = null;
                $currentListIndent = null;

                continue;
            }

            if (null !== $currentListKey && null !== $currentListIndent) {
                if ($indent > $currentListIndent && preg_match('/^\s*-\s*(.+)$/', $line, $matches)) {
                    $value = $this->stripQuotes(trim($matches[1]));
                    if ('' !== $value) {
                        $rules[$currentRuleIndex][$currentListKey][] = $value;
                    }

                    continue;
                }

                if ($indent <= $currentListIndent) {
                    $currentListKey = null;
                    $currentListIndent = null;
                }
            }

            $pair = $this->extractKeyValueFromLine($line);
            if (null === $pair) {
                continue;
            }

            [$pairKey, $value] = $pair;
            $normalizedKey = $this->normalizeRuleKey($pairKey);

            if (\in_array($normalizedKey, ['roles', 'methods', 'ips'], true)) {
                if ('' === $value) {
                    $currentListKey = $normalizedKey;
                    $currentListIndent = $indent;

                    continue;
                }

                $rules[$currentRuleIndex][$normalizedKey] = $this->parseListValue($value);

                continue;
            }

            $this->applyRuleValue($rules[$currentRuleIndex], $normalizedKey, $value);
        }

        /** @var list<AccessControlRule> $normalizedRules */
        $normalizedRules = array_values($rules);

        return $normalizedRules;
    }

    /**
     * @param array<int, string> $lines
     *
     * @return list<FirewallRule>
     */
    private function extractFirewalls(array $lines, string $path, ?string $environment): array
    {
        /** @var array<int, FirewallRule> $firewalls */
        $firewalls = [];
        $securityIndent = null;
        $firewallsIndent = null;
        $currentFirewallIndex = null;
        $currentFirewallIndent = null;
        $whenIndent = null;
        $skipWhen = false;

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $indent = \strlen($line) - \strlen($trimmed);
            $key = $this->extractKeyFromLine($line);

            if (null !== $whenIndent && $indent <= $whenIndent) {
                $whenIndent = null;
                $skipWhen = false;
            }

            if (null !== $key && 0 === $indent && str_starts_with($key, 'when@')) {
                $whenIndent = $indent;
                $skipWhen = null !== $environment && $environment !== substr($key, 5);

                continue;
            }

            if (null !== $whenIndent && $skipWhen && $indent > $whenIndent) {
                continue;
            }

            if (null !== $key && 'security' === $key) {
                $securityIndent = $indent;
                $firewallsIndent = null;
                $currentFirewallIndex = null;

                continue;
            }

            if (null !== $securityIndent && $indent <= $securityIndent) {
                $securityIndent = null;
                $firewallsIndent = null;
                $currentFirewallIndex = null;
            }

            if (null !== $securityIndent && null !== $key && 'firewalls' === $key && $indent > $securityIndent) {
                $firewallsIndent = $indent;
                $currentFirewallIndex = null;

                continue;
            }

            if (null === $firewallsIndent) {
                continue;
            }

            if ($indent <= $firewallsIndent) {
                $firewallsIndent = null;
                $currentFirewallIndex = null;

                continue;
            }

            if (
                null !== $key
                && $indent > $firewallsIndent
                && (null === $currentFirewallIndent || $indent <= $currentFirewallIndent)
            ) {
                $currentFirewallIndex = \count($firewalls);
                $currentFirewallIndent = $indent;

                $firewalls[$currentFirewallIndex] = [
                    'name' => $key,
                    'file' => $path,
                    'line' => $index + 1,
                    'pattern' => null,
                    'requestMatcher' => null,
                ];

                continue;
            }

            if (null === $currentFirewallIndex || null === $currentFirewallIndent) {
                continue;
            }

            if ($indent <= $currentFirewallIndent) {
                $currentFirewallIndex = null;
                $currentFirewallIndent = null;

                continue;
            }

            $pair = $this->extractKeyValueFromLine($line);
            if (null === $pair) {
                continue;
            }

            [$pairKey, $value] = $pair;
            if ('pattern' === $pairKey) {
                $firewalls[$currentFirewallIndex]['pattern'] = $value;
                $firewalls[$currentFirewallIndex]['line'] = $index + 1;
            }

            if ('request_matcher' === $pairKey) {
                $firewalls[$currentFirewallIndex]['requestMatcher'] = $value;
            }
        }

        /** @var list<FirewallRule> $normalizedFirewalls */
        $normalizedFirewalls = array_values($firewalls);

        return $normalizedFirewalls;
    }

    /**
     * @param AccessControlRule     $rule
     * @param array<string, string> $pairs
     */
    private function applyRulePairs(array &$rule, array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $normalizedKey = $this->normalizeRuleKey($key);

            if (\in_array($normalizedKey, ['roles', 'methods', 'ips'], true)) {
                $rule[$normalizedKey] = $this->parseListValue($value);

                continue;
            }

            $this->applyRuleValue($rule, $normalizedKey, $value);
        }
    }

    /**
     * @param AccessControlRule $rule
     */
    private function applyRuleValue(array &$rule, string $key, string $value): void
    {
        $value = $this->stripQuotes(trim($value));

        if ('' === $value && !\in_array($key, ['path', 'host'], true)) {
            return;
        }

        if ('path' === $key) {
            $rule['path'] = $value;

            return;
        }

        if ('host' === $key) {
            $rule['host'] = $value;

            return;
        }

        if ('allowIf' === $key) {
            $rule['allowIf'] = $value;

            return;
        }

        if ('requestMatcher' === $key) {
            $rule['requestMatcher'] = $value;

            return;
        }

        if ('requiresChannel' === $key) {
            $rule['requiresChannel'] = $value;
        }
    }

    private function normalizeRuleKey(string $key): string
    {
        return match ($key) {
            'role' => 'roles',
            'allow_if' => 'allowIf',
            'request_matcher' => 'requestMatcher',
            'requires_channel' => 'requiresChannel',
            default => $key,
        };
    }

    /**
     * @return array<int, string>
     */
    private function parseListValue(string $value): array
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return [];
        }

        if ('[]' === $trimmed) {
            return [];
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $inner = trim(substr($trimmed, 1, -1));
            if ('' === $inner) {
                return [];
            }

            $items = $this->splitInlineValues($inner);

            $normalized = array_map($this->stripQuotes(...), $items);

            return array_values(array_filter($normalized, static fn (string $item): bool => '' !== $item));
        }

        return [$this->stripQuotes($trimmed)];
    }

    /**
     * @return array<string, string>
     */
    private function parseInlineMapping(string $value): array
    {
        $trimmed = trim($value);
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $trimmed = trim(substr($trimmed, 1, -1));
        }

        if ('' === $trimmed) {
            return [];
        }

        $pairs = [];
        foreach ($this->splitInlineValues($trimmed) as $segment) {
            $pair = $this->extractKeyValueFromLine($segment);
            if (null === $pair) {
                continue;
            }
            [$key, $val] = $pair;
            $pairs[$key] = $val;
        }

        return $pairs;
    }

    /**
     * @return array<int, string>
     */
    private function splitInlineValues(string $value): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inQuote = null;

        $length = \strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if (null !== $inQuote) {
                if ($char === $inQuote) {
                    $inQuote = null;
                }

                $buffer .= $char;

                continue;
            }

            if ('"' === $char || '\'' === $char) {
                $inQuote = $char;
                $buffer .= $char;

                continue;
            }

            if ('[' === $char || '{' === $char) {
                $depth++;
            }

            if (']' === $char || '}' === $char) {
                $depth = max(0, $depth - 1);
            }

            if (',' === $char && 0 === $depth) {
                $parts[] = trim($buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if ('' !== trim($buffer)) {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    private function stripQuotes(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, '\'') && str_ends_with($value, '\''))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function extractKeyFromLine(string $line): ?string
    {
        if (!preg_match('/^\s*(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z0-9_.@-]+))\s*:/', $line, $matches)) {
            return null;
        }

        if ('' !== $matches[1]) {
            return $matches[1];
        }
        if ('' !== $matches[2]) {
            return $matches[2];
        }

        return $matches[3];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractKeyValueFromLine(string $line): ?array
    {
        if (!preg_match('/^\s*(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z0-9_.@-]+))\s*:\s*(.*)$/', $line, $matches)) {
            return null;
        }

        $key = '' !== $matches[1] ? $matches[1] : ('' !== $matches[2] ? $matches[2] : $matches[3]);

        return [$key, trim($matches[4])];
    }
}
