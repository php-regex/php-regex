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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\ConfirmationRunner;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Called directly, the runner runs the pattern under the locale it is
 * given, as the analyzer runs it under its target's; without one, under
 * the process's.
 */
final class ConfirmationRunnerLocaleTest extends TestCase
{
    #[Test]
    public function test_the_runner_runs_under_the_locale_it_is_given(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(\w+)+!/', RedosSeverity::Low);
        $before = CharacterTables::current();

        $underC = (new ConfirmationRunner(locale: 'C'))->confirm('/(\w+)+!/', $analysis);
        $underTheProcess = (new ConfirmationRunner())->confirm('/(\w+)+!/', $analysis);

        $this->assertSame($underTheProcess->confirmed, $underC->confirmed);
        $this->assertSame($before, CharacterTables::current());
    }

    #[Test]
    public function test_a_locale_the_machine_cannot_set_is_refused(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(\w+)+!/', RedosSeverity::Low);

        $this->expectException(InvalidRegexOptionException::class);

        (new ConfirmationRunner(locale: 'xx_XX.NOPE'))->confirm('/(\w+)+!/', $analysis);
    }
}
