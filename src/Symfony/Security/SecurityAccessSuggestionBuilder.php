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
 * @internal
 *
 * @phpstan-import-type AccessConflict from SecurityAccessControlReport
 */
final readonly class SecurityAccessSuggestionBuilder
{
    /**
     * @param (callable(string, int): string)|null $formatLocation
     *
     * @phpstan-param array<AccessConflict> $conflicts
     *
     * @return array<int, string>
     */
    public function collect(array $conflicts, ?callable $formatLocation = null): array
    {
        $suggestions = [];

        foreach ($conflicts as $conflict) {
            if ('shadowed' === $conflict['type']) {
                $rule = $conflict['rule'];
                $other = $conflict['conflict'];
                $location = $this->formatLocation($formatLocation, $other['file'], $other['line']);

                $moveSuggestion = \sprintf(
                    'Reorder access_control: move rule #%d (%s) before #%d.',
                    $other['index'],
                    $location,
                    $rule['index'],
                );
                $suggestions[$moveSuggestion] = true;

                if ('critical' === $conflict['severity']) {
                    $suggestions['Narrow the PUBLIC_ACCESS rule or move the restrictive rule above it.'] = true;
                }
            }

            if (($conflict['redundant'] ?? false) === true) {
                $rule = $conflict['conflict'];
                $location = $this->formatLocation($formatLocation, $rule['file'], $rule['line']);
                $suggestions[\sprintf(
                    'Remove redundant rule #%d (%s); it does not add new access constraints.',
                    $rule['index'],
                    $location,
                )] = true;
            }
        }

        return array_keys($suggestions);
    }

    /**
     * @param (callable(string, int): string)|null $formatter
     */
    private function formatLocation(?callable $formatter, string $file, int $line): string
    {
        if (null !== $formatter) {
            $value = $formatter($file, $line);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return $file.':'.$line;
    }
}
