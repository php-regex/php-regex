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

use PHPUnit\Event\Test\DeprecationTriggered;
use PHPUnit\Event\Test\DeprecationTriggeredSubscriber;
use PHPUnit\Event\Test\PhpDeprecationTriggered;
use PHPUnit\Event\Test\PhpDeprecationTriggeredSubscriber;
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
 */
final class SourceDeprecationListener implements DeprecationTriggeredSubscriber, Extension, PhpDeprecationTriggeredSubscriber
{
    private readonly string $sourceDirectory;

    public function __construct(?string $sourceDirectory = null)
    {
        $this->sourceDirectory = $sourceDirectory ?? \dirname(__DIR__, 2).'/src';
    }

    /**
     * Does a deprecation frame belong to the source this policy guards?
     * The comparison runs on the resolved directory, so a relative or
     * symlinked spelling of it still matches.
     */
    public static function isOwnSource(string $file, string $sourceDirectory): bool
    {
        $source = realpath($sourceDirectory);

        return false !== $source && str_starts_with($file, $source.\DIRECTORY_SEPARATOR);
    }

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $listener = $parameters->has('source')
            ? new self($parameters->get('source'))
            : $this;

        $facade->registerSubscriber($listener);
    }

    public function notify(DeprecationTriggered|PhpDeprecationTriggered $event): void
    {
        $this->guard($event->message(), $event->file(), $event->line());
    }

    private function guard(string $message, string $file, int $line): void
    {
        if (!self::isOwnSource($file, $this->sourceDirectory)) {
            return;
        }

        throw new \RuntimeException(\sprintf(
            'This library deprecated itself: "%s" at %s:%d. A deprecation under src/ fails the suite.',
            $message,
            $file,
            $line,
        ));
    }
}
