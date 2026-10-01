<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Symfony\Security;

/**
 * @internal
 */
final readonly class SecurityConfigLocator
{
    /**
     * @return array<int, string>
     */
    public function locate(?string $projectDir, ?string $environment): array
    {
        $root = $projectDir ?? (getcwd() ?: '');
        if ('' === $root) {
            return [];
        }

        $paths = [
            $root.'/config/packages/security.yaml',
            $root.'/config/packages/security.yml',
        ];

        if (null !== $environment && '' !== $environment) {
            $paths[] = $root.'/config/packages/'.$environment.'/security.yaml';
            $paths[] = $root.'/config/packages/'.$environment.'/security.yml';
        }

        $paths[] = $root.'/config/security.yaml';
        $paths[] = $root.'/config/security.yml';

        $existing = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $existing[] = $path;
            }
        }

        return array_values(array_unique($existing));
    }
}
