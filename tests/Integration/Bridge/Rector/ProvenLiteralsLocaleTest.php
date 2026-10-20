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

namespace PHPRegex\Tests\Integration\Bridge\Rector;

use PHPRegex\Rector\PregCall;
use PHPRegex\Rector\ProvenLiterals;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Rector rules judge the PHP the code targets under the locale Rector
 * runs with, which names no other: a proof made under one locale is not
 * another's, though the PHP version is the same.
 */
final class ProvenLiteralsLocaleTest extends TestCase
{
    use InstalledLocales;

    #[Test]
    #[RunInSeparateProcess]
    public function test_each_locale_rector_runs_under_gets_its_own_target(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The targets of the Rector rules under a Latin-1 locale');
        $literals = new ProvenLiterals();

        setlocale(\LC_CTYPE, 'C');
        $this->assertSame('C', PregCall::targetParser(80400)->target()->locale);
        $this->assertSame('abc', $literals->of('/abc/', 80400));

        setlocale(\LC_CTYPE, 'fr_FR.ISO8859-1');
        $this->assertSame('fr_FR.ISO8859-1', PregCall::targetParser(80400)->target()->locale);
        $this->assertSame('abc', $literals->of('/abc/', 80400));

        /** @var array<string, mixed> $targets */
        $targets = (new \ReflectionProperty($literals, 'targets'))->getValue($literals);
        $this->assertSame(['80400/10.44/C', '80400/10.44/fr_FR.ISO8859-1'], array_keys($targets));
    }
}
