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

namespace PHPRegex\Tests\Support;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The deprecation policy of the suite, wired in phpunit.xml.dist: a PHP
 * deprecation — engine or user-level, PHPUnit's own API deprecations
 * excepted — whose frame lands under the source directory fails the run,
 * because this library deprecating itself must never be silent. Every
 * deprecation a vendor or a tool triggers stays PHPUnit's display-only
 * signal: the weekly re-resolved locks deprecate for reasons that are not
 * this library's.
 *
 * The two channels are two subscriber classes on purpose: PHPUnit maps a
 * subscriber to one event class only, so a single class implementing both
 * subscriber interfaces would silently drop one of them.
 */
final class SourceDeprecationListener implements Extension
{
    public static function isOwnSource(string $file, string $sourceDirectory): bool
    {
        // Compared with "/": a frame on Windows spells its file with "\\". The
        // extension runs where the library is not loaded, so no helper of it.
        $source = realpath($sourceDirectory);

        return false !== $source && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $source).'/');
    }

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $sourceDirectory = $parameters->has('source')
            ? $parameters->get('source')
            : \dirname(__DIR__, 2).'/src';

        $facade->registerSubscriber(new EngineDeprecationSubscriber($sourceDirectory));
        $facade->registerSubscriber(new UserDeprecationSubscriber($sourceDirectory));
    }
}
