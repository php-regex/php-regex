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
 * The security files of a project, in the formats the kernel loads.
 *
 * @internal
 */
final class SecurityConfigLocator
{
    private const EXTENSIONS = ['yaml', 'yml', 'php', 'xml'];

    /**
     * @return list<string>
     */
    public function locate(?string $projectDir, ?string $environment): array
    {
        $root = $projectDir ?? (getcwd() ?: '');
        if ('' === $root) {
            return [];
        }

        $directories = [$root.'/config/packages'];
        if (null !== $environment && '' !== $environment) {
            $directories[] = $root.'/config/packages/'.$environment;
        }
        $directories[] = $root.'/config';

        $paths = [];
        foreach ($directories as $directory) {
            foreach (self::EXTENSIONS as $extension) {
                $paths[] = $directory.'/security.'.$extension;
            }
        }

        $existing = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $existing[] = $path;
            }
        }

        return $existing;
    }
}
