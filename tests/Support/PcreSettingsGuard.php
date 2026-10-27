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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Wired in the PHPUnit configuration: the PCRE settings are set for the
 * whole process, and a test that lowers a limit, turns the JIT off,
 * installs the library's ini setter or leaves a window of the library's
 * limits open without putting it back hands the next test, whichever the
 * random order picks, a library that may no longer read its own regexes. Each test is checked once it ends: what it
 * left changed is put back, and the run fails naming it.
 *
 * Two subscriber classes, one per event: PHPUnit maps a subscriber to one
 * event class only.
 */
final class PcreSettingsGuard implements Extension
{
    /**
     * The settings the guard reads, by name.
     */
    public const SETTINGS = ['pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.jit'];

    /**
     * What a test found when it started.
     *
     * @var array{ini: array<string, string|false>, setter: \Closure|null, depth: int}|null
     */
    private ?array $before = null;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new PcreSettingsSnapshotSubscriber($this));
        $facade->registerSubscriber(new PcreSettingsCheckSubscriber($this));
    }

    public function snapshot(): void
    {
        $this->before = self::read();
    }

    /**
     * What the test that just ended left changed, each setting with the
     * value it found and the value it left; the settings are put back.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function restore(): array
    {
        $before = $this->before;
        $this->before = null;
        if (null === $before) {
            return [];
        }

        $after = self::read();
        $changed = [];
        foreach ($before['ini'] as $key => $value) {
            if ($after['ini'][$key] === $value) {
                continue;
            }

            $changed[$key] = [self::show($value), self::show($after['ini'][$key])];
            if (false !== $value) {
                ini_set($key, $value);
            }
        }

        if ($after['setter'] !== $before['setter']) {
            $changed['LibraryPcre::useIniSetter()'] = [self::showSetter($before['setter']), self::showSetter($after['setter'])];
            LibraryPcre::useIniSetter($before['setter']);
        }

        if ($after['depth'] !== $before['depth']) {
            // A window left open: the library takes every call for one made
            // inside it, and raises no limit for its own regexes.
            $changed['LibraryPcre window depth'] = [(string) $before['depth'], (string) $after['depth']];
            (new \ReflectionClass(LibraryPcre::class))->setStaticPropertyValue('depth', $before['depth']);
            (new \ReflectionClass(LibraryPcre::class))->setStaticPropertyValue('windowRaised', []);
        }

        return $changed;
    }

    /**
     * @return array{ini: array<string, string|false>, setter: \Closure|null, depth: int}
     */
    private static function read(): array
    {
        $ini = [];
        foreach (self::SETTINGS as $key) {
            $ini[$key] = ini_get($key);
        }

        $library = new \ReflectionClass(LibraryPcre::class);
        $setter = $library->getStaticPropertyValue('iniSetter');
        $depth = $library->getStaticPropertyValue('depth');

        return ['ini' => $ini, 'setter' => $setter instanceof \Closure ? $setter : null, 'depth' => \is_int($depth) ? $depth : 0];
    }

    private static function show(string|false $value): string
    {
        return false === $value ? '(unreadable)' : '"'.$value.'"';
    }

    private static function showSetter(?\Closure $setter): string
    {
        return null === $setter ? 'ini_set' : 'a closure';
    }
}
