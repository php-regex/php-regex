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

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * A row that holds for one LC_CTYPE locale runs where the machine has it:
 * the locales of a Linux image are often "C" and "C.UTF-8" alone. Where it
 * is missing, the library must refuse it, which is checked before the row
 * is skipped.
 */
trait InstalledLocales
{
    private function requiresLocale(string $locale, string $subject): CharacterTables
    {
        try {
            return CharacterTables::forLocale($locale);
        } catch (InvalidRegexOptionException $exception) {
            $this->assertStringContainsString('"'.$locale.'"', $exception->getMessage());
            $this->markTestSkipped(\sprintf('%s is verified against PHP under the "%s" locale, which this machine cannot set; the library refuses it.', $subject, $locale));
        }
    }
}
